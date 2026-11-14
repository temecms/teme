import { usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useAssistant } from '@/components/editor/assistant/assistant-context';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { ContentEditPage, EditorAi } from '@/types/editor';
import {
    METADATA_FIELDS,
    METADATA_TASK,
    METADATA_WORDS,
    useMetadata,
} from './use-metadata';

/** What both buttons ask: the whole item, no target (I-1313). */
const ASK = { task: METADATA_TASK, scope: METADATA_WORDS.scope };

type Suggestion = ReturnType<typeof useMetadata>;

/**
 * "Suggest metadata" in the head of Search and social while it is open
 * (I-1313, I-3124): asking, and, while proposals wait under their fields, Use
 * all and Discard.
 */
export function SuggestMetadataButtons({ state }: { state: Suggestion }) {
    const { ask } = useAssistant();
    const { working, proposals, busy, use, putAside } = state;
    const waiting = Object.keys(proposals);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                variant="outline"
                data-test="metadata-suggest"
                disabled={working || busy}
                onClick={() => void ask(ASK)}
            >
                {working ? <Spinner /> : <Sparkles />}
                {working ? METADATA_WORDS.working : METADATA_WORDS.suggest}
            </Button>
            {waiting.length > 0 && (
                <>
                    <Button
                        size="sm"
                        data-test="metadata-use-all"
                        disabled={busy}
                        onClick={() => void use(waiting)}
                    >
                        {busy ? METADATA_WORDS.using : 'Use all'}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="metadata-discard"
                        disabled={busy}
                        onClick={() => void putAside()}
                    >
                        Discard
                    </Button>
                </>
            )}
        </div>
    );
}

/**
 * The line under the head of Search and social while it is open (I-1313,
 * I-3124): the hint, or where the proposals wait, and a failure's alert.
 */
export function SuggestMetadataNote({ state }: { state: Suggestion }) {
    const { proposals, message } = state;

    return (
        <div className="grid gap-2">
            <p className="text-ink-2 text-xs">
                {Object.keys(proposals).length > 0
                    ? METADATA_WORDS.waiting
                    : METADATA_WORDS.hint}
            </p>
            {message !== null && (
                <p role="alert" className="text-danger-ink text-xs">
                    {message}
                </p>
            )}
        </div>
    );
}

/**
 * The assistant pane's metadata section (I-1313): what it proposes, and
 * Suggest metadata. Shown when the task is offered and the item has one of
 * its fields; a theme's region has none.
 */
export function SuggestMetadataPane({ ai }: { ai: EditorAi }) {
    const { ask, request } = useAssistant();
    const { fields } = usePage<ContentEditPage>().props;
    const working =
        request !== null &&
        request.task === METADATA_TASK &&
        (request.state === 'asking' || request.state === 'working');

    if (
        !ai.tasks.some((task) => task.key === METADATA_TASK) ||
        !fields.some((field) => METADATA_FIELDS.includes(field.handle))
    ) {
        return null;
    }

    return (
        <div
            role="group"
            aria-labelledby="assistant-metadata"
            className="grid gap-2"
        >
            <h3 id="assistant-metadata" className="text-xs font-medium">
                Search and social
            </h3>
            <p className="text-ink-2 text-xs">{METADATA_WORDS.paneHint}</p>
            <div>
                <Button
                    size="sm"
                    variant="outline"
                    data-test="metadata-suggest-pane"
                    disabled={working}
                    onClick={() => void ask(ASK)}
                >
                    {working ? <Spinner /> : <Sparkles />}
                    {working ? METADATA_WORDS.working : METADATA_WORDS.suggest}
                </Button>
            </div>
        </div>
    );
}
