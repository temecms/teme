import { useMemo, useState } from 'react';
import type { Dispatch, SetStateAction } from 'react';
import AiRequestController from '@/actions/App/Http/Admin/Ai/AiRequestController';
import {
    ASSISTANT_WORDS,
    useAssistant,
} from '@/components/editor/assistant/assistant-context';
import type { AssistantSaving } from '@/components/editor/assistant/assistant-context';
import { send } from '@/components/editor/editor-http';
import { showToast } from '@/components/flash-toast';
import type { EditorAi, EditorField } from '@/types/editor';

/** The metadata task's key (MetadataTask::KEY). */
export const METADATA_TASK = 'metadata';

/** The fields it proposes, by handle, in its order (MetadataTask::LIMITS). */
export const METADATA_FIELDS: readonly string[] = [
    'seo_title',
    'meta_description',
    'excerpt',
    'social_title',
    'social_description',
];

/** The words the metadata surfaces share (I-1313). */
export const METADATA_WORDS = {
    scope: 'Metadata: search and social text, and the excerpt',
    suggest: 'Suggest metadata',
    working: 'Working…',
    using: 'Using…',
    hint: 'AI proposes an SEO title, a meta description, an excerpt and a social title and description. Nothing changes until you choose Use.',
    paneHint:
        'AI proposes an SEO title, a meta description, an excerpt and a social title and description, each under its own field.',
    waiting:
        'Each proposal is under its field. Use takes one, and Use all takes every one still waiting.',
    usedAll: 'Suggestions used as one change.',
};

/** What the editor lends its metadata proposals: its fields and their values, how to change them, its saving, and what was used. */
export type MetadataBinding = {
    /** Whether the assistant offers this person the metadata task now. */
    offered: boolean;
    fields: EditorField[];
    values: Record<string, unknown>;
    setValues: Dispatch<SetStateAction<Record<string, unknown>>>;
    saving: AssistantSaving;
    /** The handles of this request's proposals used already. */
    usedFor: (requestId: string) => string[];
    markUsed: (requestId: string, handles: string[]) => void;
    /** Whether a Use is under way anywhere: one at a time, so a second never sends the base the first is moving. */
    busy: boolean;
    setBusy: (busy: boolean) => void;
};

/**
 * The editor's side of the metadata proposals, made once in the editor,
 * where its fields live: what they hold, how to change them, its saving,
 * and which of the request's proposals were used, so none is offered twice.
 */
export function useMetadataBinding({
    ai,
    fields,
    values,
    setValues,
    saving,
}: {
    ai: EditorAi | null;
    fields: EditorField[];
    values: Record<string, unknown>;
    setValues: Dispatch<SetStateAction<Record<string, unknown>>>;
    saving: AssistantSaving;
}): MetadataBinding {
    const [used, setUsed] = useState<{ id: string; handles: string[] } | null>(
        null,
    );
    const [busy, setBusy] = useState(false);

    return useMemo(
        () => ({
            offered:
                ai?.tasks.some((task) => task.key === METADATA_TASK) ?? false,
            fields,
            values,
            setValues,
            saving,
            usedFor: (requestId: string) =>
                used !== null && used.id === requestId ? used.handles : [],
            markUsed: (requestId: string, handles: string[]) =>
                setUsed((current) =>
                    current !== null && current.id === requestId
                        ? {
                              id: requestId,
                              handles: [...current.handles, ...handles],
                          }
                        : { id: requestId, handles },
                ),
            busy,
            setBusy,
        }),
        [ai, fields, values, setValues, saving, used, busy],
    );
}

/** A field's text, or nothing. */
function textOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/** "1 character", "47 characters": code points, as the server counts them. */
export function characterCount(text: string): string {
    const n = Array.from(text).length;

    return `${n} ${n === 1 ? 'character' : 'characters'}`;
}

/**
 * The metadata request's proposals still to use, and Use (I-1313). A
 * proposal is offered while the request is ready, the item has its field,
 * it wasn't used, and the field holds something else. Use saves first, has
 * the server write the fields as the assistant's revision, then sets them
 * as one change; the toast's Undo puts the earlier values back.
 */
export function useMetadata(binding: MetadataBinding) {
    const { request, putAside } = useAssistant();
    const { busy, setBusy } = binding;
    const [failure, setFailure] = useState<{
        id: string;
        message: string;
    } | null>(null);

    const mine =
        request !== null && request.task === METADATA_TASK ? request : null;
    const working =
        mine !== null && (mine.state === 'asking' || mine.state === 'working');
    const keyOf = (handle: string) =>
        binding.fields.find((field) => field.handle === handle)?.key;
    const proposals: Record<string, string> = {};

    if (mine !== null && mine.id !== null && mine.state === 'ready') {
        const used = binding.usedFor(mine.id);

        for (const [handle, proposal] of Object.entries(mine.values)) {
            const key = keyOf(handle);

            if (
                key !== undefined &&
                !used.includes(handle) &&
                textOf(binding.values[key]).trim() !== proposal.trim()
            ) {
                proposals[handle] = proposal;
            }
        }
    }

    const use = async (handles: string[]): Promise<void> => {
        if (busy || mine === null || mine.id === null || handles.length === 0) {
            return;
        }

        const requestId = mine.id;
        const waiting = Object.keys(proposals);
        setBusy(true);
        setFailure(null);

        try {
            const revisionId = await binding.saving.checkpoint();

            if (revisionId === null) {
                setFailure({
                    id: requestId,
                    message: ASSISTANT_WORDS.notSaved,
                });

                return;
            }

            const answer = await send(AiRequestController.apply(requestId), {
                revision_id: revisionId,
                fields: handles,
            });

            if (answer.status === 200) {
                const before: Record<string, unknown> = {};
                const after: Record<string, unknown> = {};

                for (const handle of handles) {
                    const key = keyOf(handle);

                    if (key !== undefined) {
                        before[key] = binding.values[key] ?? '';
                        after[key] = mine.values[handle];
                    }
                }

                // The server's revision is the base, and this change is in it.
                binding.saving.adopt(String(answer.body.revisionId));
                binding.setValues((current) => ({ ...current, ...after }));
                binding.markUsed(requestId, handles);
                showToast({
                    type: 'success',
                    message:
                        handles.length === 1
                            ? ASSISTANT_WORDS.used
                            : METADATA_WORDS.usedAll,
                    action: {
                        label: 'Undo',
                        onClick: () =>
                            binding.setValues((current) => ({
                                ...current,
                                ...before,
                            })),
                    },
                });

                // Every proposal used: the request is done.
                if (waiting.every((handle) => handles.includes(handle))) {
                    await putAside();
                }

                return;
            }

            if (answer.status === 409 && answer.body.stale === true) {
                // Saved elsewhere since: the editor's own stale dialog.
                binding.saving.markStale(answer.body);

                return;
            }

            setFailure({
                id: requestId,
                message:
                    answer.status === 409 &&
                    typeof answer.body.message === 'string'
                        ? answer.body.message
                        : ASSISTANT_WORDS.unexpected,
            });
        } catch {
            setFailure({ id: requestId, message: ASSISTANT_WORDS.offline });
        } finally {
            setBusy(false);
        }
    };

    return {
        working,
        proposals,
        busy,
        message:
            failure !== null && mine !== null && failure.id === mine.id
                ? failure.message
                : null,
        use,
        putAside,
    };
}
