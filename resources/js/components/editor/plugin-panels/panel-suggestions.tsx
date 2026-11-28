import type { Editor } from '@tiptap/core';
import { useState } from 'react';
import EditorPanelController from '@/actions/App/Http/Admin/Contents/EditorPanelController';
import { Proposal } from '@/components/ai/proposal';
import { send } from '@/components/editor/editor-http';
import { showToast } from '@/components/flash-toast';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type {
    EditorPluginPanel,
    PanelAnswer,
    PanelChange,
    PanelSuggestion,
} from '@/types';
import { applyBody, applyLink, plainText } from './apply-change';
import { PANEL_WORDS } from './panel-words';

/** An undo for a change just made, or null when it couldn't be made. */
type Undo = (() => void) | null;

/** What a panel needs of the editor to use a suggestion: nothing is saved until the person saves. */
export type PanelApply = {
    editor: Editor | null;
    /** The editor has changes that are not saved yet. */
    unsaved: boolean;
    checkpoint: () => Promise<string | null>;
    markStale: (answer: Record<string, unknown>) => void;
    /** Sets the title; returns how to put the old one back. */
    title: (title: string) => Undo;
    /** Sets a field by its key; returns how to put the old value back. */
    field: (key: string, value: string | number | boolean | null) => Undo;
    /** Adds the terms, creating those that don't exist yet by the person's own rights. */
    terms: (change: Extract<PanelChange, { kind: 'terms' }>) => Promise<Undo>;
};

/** The suggestions' own sentences. */
const WORDS = {
    used: 'Suggestion used as one change.',
    phraseGone: 'The phrase isn’t in the article any more.',
    termsFailed:
        'Some of these terms couldn’t be added, so nothing was changed.',
};

/**
 * A panel's suggestions: what each would change, then Use and Discard.
 * Using one is one change the person can undo; a whole article opens as a
 * proposal with the word diff first. Either way the plugin is told.
 */
export function PanelSuggestions({
    panel,
    contentId,
    suggestions,
    apply,
    onAnswer,
}: {
    panel: EditorPluginPanel;
    contentId: string;
    suggestions: PanelSuggestion[];
    apply: PanelApply;
    onAnswer: (answer: PanelAnswer) => void;
}) {
    const [gone, setGone] = useState<string[]>([]);
    const [working, setWorking] = useState<string | null>(null);
    const [opened, setOpened] = useState<string | null>(null);
    const [problems, setProblems] = useState<Record<string, string>>({});

    const settle = async (suggestion: PanelSuggestion, used: boolean) => {
        setGone((was) => [...was, suggestion.id]);

        try {
            const answer = await send(
                EditorPanelController.settle({
                    content: contentId,
                    code: panel.code,
                    panel: panel.id,
                    suggestion: suggestion.id,
                }),
                { used },
            );

            if (answer.status === 200) {
                onAnswer(answer.body as unknown as PanelAnswer);
            } else if (answer.status === 422) {
                const errors = (answer.body.errors ?? {}) as Record<
                    string,
                    string[]
                >;

                showToast({
                    type: 'error',
                    message:
                        errors.suggestion?.[0] ?? __(PANEL_WORDS.unexpected),
                });
            }
        } catch {
            showToast({ type: 'error', message: __(PANEL_WORDS.offline) });
        }
    };

    const used = (suggestion: PanelSuggestion, undo: () => void) => {
        showToast({
            type: 'success',
            message: __(WORDS.used),
            action: { label: __('Undo'), onClick: undo },
        });
        void settle(suggestion, true);
    };

    const editorUndo = () => apply.editor?.chain().focus().undo().run();

    const use = async (suggestion: PanelSuggestion) => {
        const { change } = suggestion;
        setProblems((was) => ({ ...was, [suggestion.id]: '' }));

        if (change.kind === 'body') {
            setOpened(suggestion.id);

            return;
        }

        if (change.kind === 'title') {
            const undo = apply.title(change.title);

            if (undo !== null) {
                used(suggestion, undo);
            }

            return;
        }

        if (change.kind === 'field') {
            const undo = apply.field(change.key, change.value);

            if (undo !== null) {
                used(suggestion, undo);
            }

            return;
        }

        if (change.kind === 'terms') {
            setWorking(suggestion.id);
            const undo = await apply.terms(change);
            setWorking(null);

            if (undo === null) {
                setProblems((was) => ({
                    ...was,
                    [suggestion.id]: __(WORDS.termsFailed),
                }));
            } else {
                used(suggestion, undo);
            }

            return;
        }

        if (
            apply.editor !== null &&
            applyLink(
                apply.editor,
                change.phrase,
                change.itemId,
                change.occurrence,
            )
        ) {
            used(suggestion, editorUndo);
        } else {
            setProblems((was) => ({
                ...was,
                [suggestion.id]: __(WORDS.phraseGone),
            }));
        }
    };

    const shown = suggestions.filter((each) => !gone.includes(each.id));

    return (
        <ul className="grid gap-3" data-test="panel-suggestions">
            {shown.map((suggestion) => (
                <li
                    key={suggestion.id}
                    data-test={`panel-suggestion-${suggestion.id}`}
                    className="border-line grid gap-2 rounded-md border p-3"
                >
                    <p className="text-ink font-medium">{suggestion.label}</p>
                    {suggestion.detail !== '' && (
                        <p className="text-ink-2">{suggestion.detail}</p>
                    )}
                    <ChangeLine change={suggestion.change} />

                    {opened === suggestion.id &&
                    suggestion.change.kind === 'body' ? (
                        <Proposal
                            before={
                                apply.editor?.getText({
                                    blockSeparator: '\n\n',
                                }) ?? ''
                            }
                            after={plainText(suggestion.change.document)}
                            onUse={() => {
                                const change = suggestion.change;

                                if (
                                    change.kind === 'body' &&
                                    apply.editor !== null &&
                                    applyBody(apply.editor, change.document)
                                ) {
                                    setOpened(null);
                                    used(suggestion, editorUndo);
                                }
                            }}
                            onDiscard={() => {
                                setOpened(null);
                                void settle(suggestion, false);
                            }}
                        />
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                loading={working === suggestion.id}
                                data-test="panel-suggestion-use"
                                onClick={() => void use(suggestion)}
                            >
                                {suggestion.change.kind === 'body'
                                    ? __('Review the changes')
                                    : __('Use')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                data-test="panel-suggestion-discard"
                                onClick={() => void settle(suggestion, false)}
                            >
                                {__('Discard')}
                            </Button>
                        </div>
                    )}

                    {(problems[suggestion.id] ?? '') !== '' && (
                        <p role="alert" className="text-danger-ink">
                            {problems[suggestion.id]}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** What a suggestion would change, in words. */
function ChangeLine({ change }: { change: PanelChange }) {
    switch (change.kind) {
        case 'title':
            return (
                <p className="text-ink-2">
                    {__('Title: :title', { title: change.title })}
                </p>
            );
        case 'field':
            return (
                <p className="text-ink-2">
                    {__(':field: :value', {
                        field: change.label,
                        value:
                            change.value === null
                                ? __('(empty)')
                                : typeof change.value === 'boolean'
                                  ? change.value
                                      ? __('Yes')
                                      : __('No')
                                  : String(change.value),
                    })}
                </p>
            );
        case 'terms':
            return (
                <p className="text-ink-2">
                    {__(':taxonomy: :terms', {
                        taxonomy: change.taxonomyLabel,
                        terms: [
                            ...change.terms.map((term) => term.name),
                            ...change.create,
                        ].join(', '),
                    })}
                </p>
            );
        case 'link':
            return (
                <p className="text-ink-2">
                    {__('Link “:phrase” to :target', {
                        phrase: change.phrase,
                        target: change.target,
                    })}
                </p>
            );
        default:
            return (
                <p className="text-ink-2">
                    {__(
                        'Replaces the whole article. Review it before you use it.',
                    )}
                </p>
            );
    }
}
