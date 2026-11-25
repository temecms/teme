import { usePage } from '@inertiajs/react';
import type { JSONContent } from '@tiptap/core';
import { EditorContent } from '@tiptap/react';
import { useRef } from 'react';
import { CardSettingsOpen } from '@/components/editor/cards/card-frame';
import { useTemeEditor } from '@/components/editor/use-teme-editor';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __ } from '@/lib/i18n';
import type { CardManifest } from '@/types';

/**
 * One block's editor, the block alone in a document: the editor's own card
 * views and forms, with each card's settings open, so a card is edited here
 * exactly as it is in the full editor.
 */
function OneBlockEditor({
    block,
    manifest,
    siteUrl,
    onChange,
}: {
    block: JSONContent;
    manifest: CardManifest;
    siteUrl: string;
    onChange: (blocks: JSONContent[]) => void;
}) {
    const { preferences } = usePage().props;
    const editor = useTemeEditor({
        document: { type: 'doc', content: [block] },
        cards: manifest.cards,
        embedProviders: manifest.embedProviders,
        spellcheck: preferences['editor.spellcheck'] !== false,
        smartQuotes: preferences['editor.smart_quotes'] !== false,
        siteUrl,
        onChange: (document) => onChange(document.content ?? []),
    });

    return (
        <CardSettingsOpen value>
            <div
                data-test="region-card-editor"
                className="border-line rounded-md border [&_.teme-prose]:min-h-24 [&_.teme-prose]:px-3"
            >
                <EditorContent editor={editor} />
            </div>
        </CardSettingsOpen>
    );
}

/**
 * Edit, on Content › Regions: one block's form in place. Done puts what it
 * holds back in the region, in the block's place; Cancel leaves the block as
 * it was.
 */
export function EditCardDialog({
    editing,
    manifest,
    siteUrl,
    onDone,
    onCancel,
}: {
    /** The block being edited and what it is called; null when closed. */
    editing: { block: JSONContent; label: string } | null;
    manifest: CardManifest;
    siteUrl: string;
    /** What the block became: itself, more blocks, or none if it was deleted. */
    onDone: (blocks: JSONContent[]) => void;
    onCancel: () => void;
}) {
    const latest = useRef<JSONContent[] | null>(null);

    return (
        <Dialog
            open={editing !== null}
            onOpenChange={(open) => {
                if (!open) {
                    latest.current = null;
                    onCancel();
                }
            }}
        >
            <ResponsiveDialogContent size="lg" data-test="edit-card-dialog">
                <DialogHeader>
                    <DialogTitle>
                        {__('Edit :card', { card: editing?.label ?? '' })}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'Changes show in the region when you choose Done, and are saved as a draft. Readers see them once you publish.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                {editing !== null && (
                    <OneBlockEditor
                        key={String(editing.block.attrs?.id ?? '')}
                        block={editing.block}
                        manifest={manifest}
                        siteUrl={siteUrl}
                        onChange={(blocks) => {
                            latest.current = blocks;
                        }}
                    />
                )}
                <DialogFooter>
                    <Button
                        type="button"
                        variant="neutral"
                        onClick={() => {
                            latest.current = null;
                            onCancel();
                        }}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        data-test="edit-card-done"
                        onClick={() => {
                            const blocks = latest.current;
                            latest.current = null;

                            if (blocks === null) {
                                onCancel();

                                return;
                            }

                            // An empty line the editor keeps after a card isn't a block of the region.
                            onDone(
                                blocks.filter(
                                    (block, index) =>
                                        !(
                                            block.type === 'paragraph' &&
                                            (block.content ?? []).length ===
                                                0 &&
                                            index > 0
                                        ),
                                ),
                            );
                        }}
                    >
                        {__('Done')}
                    </Button>
                </DialogFooter>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
