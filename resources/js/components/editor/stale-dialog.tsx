import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { StaleChange } from './use-autosave';

type Node = { type?: string; text?: string; content?: Node[] };

const BLOCKS = new Set([
    'paragraph',
    'heading',
    'blockquote',
    'codeBlock',
    'listItem',
    'taskItem',
    'tableRow',
]);

/**
 * A document as plain text: its words, a blank line between blocks. Cards
 * are left out; they are not text someone typed.
 */
export function documentText(document: unknown): string {
    const blocks: string[] = [];

    const walk = (node: Node): string =>
        node.text ?? (node.content ?? []).map(walk).join('');

    const collect = (node: Node) => {
        if (node.type !== undefined && BLOCKS.has(node.type)) {
            const text = walk(node).trim();

            if (text !== '') {
                blocks.push(text);
            }

            return;
        }

        (node.content ?? []).forEach(collect);
    };

    collect(document as Node);

    return blocks.join('\n\n');
}

/**
 * When a save is refused because someone else changed the article: who,
 * when, and three ways on. It never resolves itself.
 */
export function StaleDialog({
    stale,
    title,
    noun,
    document,
    onSaveAsDraft,
    onLeave,
}: {
    stale: StaleChange | null;
    title: string;
    /** What the item is called, lower case: post, page, region. */
    noun: string;
    document: unknown;
    /** Absent where a second item can't be made, as for a theme region: the choice isn't offered. */
    onSaveAsDraft?: () => Promise<string | null>;
    /** Lets the page go without asking: the person has chosen to leave their changes. */
    onLeave: () => void;
}) {
    const [copied, setCopied] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const when = stale?.at
        ? new Date(stale.at).toLocaleString([], {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : null;

    return (
        <Dialog open={stale !== null}>
            <DialogContent
                onEscapeKeyDown={(event) => event.preventDefault()}
                onInteractOutside={(event) => event.preventDefault()}
                className="[&>button:last-child]:hidden"
            >
                <DialogHeader>
                    <DialogTitle>{`Someone else changed this ${noun}`}</DialogTitle>
                    <DialogDescription>
                        {stale?.by ?? 'Someone'} saved a new version
                        {when ? ` on ${when}` : ''}, so your changes were not
                        saved over it. Your work is still here, on this page.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2 text-[13px]">
                    <p>
                        <strong>Copy my text</strong> puts your words on the
                        clipboard.{' '}
                        {onSaveAsDraft && (
                            <>
                                <strong>Save mine as a new draft</strong> keeps
                                everything you have here in a draft of its
                                own.{' '}
                            </>
                        )}
                        <strong>Open the current version</strong> shows theirs,
                        and your unsaved changes here are lost.
                    </p>
                    {problem && (
                        <p role="alert" className="text-danger-ink">
                            {problem}
                        </p>
                    )}
                </div>
                <DialogFooter className="gap-2 sm:justify-start">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            void navigator.clipboard
                                .writeText(
                                    `${title}\n\n${documentText(document)}`,
                                )
                                .then(
                                    () => setCopied(true),
                                    () =>
                                        setProblem(
                                            'The browser would not copy. Select the text and copy it yourself.',
                                        ),
                                );
                        }}
                    >
                        {copied ? 'Copied' : 'Copy my text'}
                    </Button>
                    {onSaveAsDraft && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={() => {
                                setSaving(true);
                                void onSaveAsDraft().then((error) => {
                                    setSaving(false);
                                    setProblem(error);
                                });
                            }}
                        >
                            Save mine as a new draft
                        </Button>
                    )}
                    <Button
                        type="button"
                        onClick={() => {
                            onLeave();
                            window.location.reload();
                        }}
                    >
                        Open the current version
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
