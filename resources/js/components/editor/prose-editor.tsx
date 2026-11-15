import type { Editor } from '@tiptap/core';
import { EditorContent } from '@tiptap/react';
import type { JSONContent } from '@tiptap/react';
import { Plus } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { CardManifestEntry, EditorDocument } from '@/types';
import { blockName } from './block-actions';
import type { TopBlock } from './block-actions';
import { BlockHandles } from './block-handle';
import { BubbleMenu } from './bubble-menu';
import { useCardEnvironment } from './cards/card-environment';
import { PastedImagesBar } from './pasted-image-view';
import { WordCount } from './rail/document-panel';
import type { EditorContext } from './region-entry';
import { BlockPicker } from './slash-menu';
import type { PickerRequest } from './slash-menu';
import { Toolbar } from './toolbar';
import { useTemeEditor } from './use-teme-editor';
import { isTouch } from './use-touch';

/**
 * The "+" between two blocks: a round accent "+" over a faint accent line,
 * shown where the pointer rests between two blocks, never on touch. The
 * keyboard reaches the same picker from the handle's "+".
 */
function BetweenBlocks({
    editor,
    onAdd,
}: {
    editor: Editor;
    onAdd: (at: number, button: HTMLElement) => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [gap, setGap] = useState<{ at: number; y: number } | null>(null);

    useEffect(() => {
        const wrapper = box.current?.parentElement;

        if (wrapper === null || wrapper === undefined) {
            return;
        }

        const move = (event: MouseEvent) => {
            if (isTouch() || box.current?.contains(event.target as Node)) {
                return;
            }

            const blocks = Array.from(editor.view.dom.children);
            const origin = wrapper.getBoundingClientRect().top;
            let at = 0;

            for (let index = 0; index < blocks.length - 1; index++) {
                at += editor.state.doc.maybeChild(index)?.nodeSize ?? 0;

                const bottom = blocks[index].getBoundingClientRect().bottom;
                const top = blocks[index + 1].getBoundingClientRect().top;
                const middle = (bottom + top) / 2;

                if (
                    Math.abs(event.clientY - middle) <=
                    Math.max(6, (top - bottom) / 2)
                ) {
                    setGap({ at, y: middle - origin });

                    return;
                }
            }

            setGap(null);
        };
        const leave = () => setGap(null);

        wrapper.addEventListener('mousemove', move);
        wrapper.addEventListener('mouseleave', leave);

        return () => {
            wrapper.removeEventListener('mousemove', move);
            wrapper.removeEventListener('mouseleave', leave);
        };
    }, [editor]);

    return (
        <div
            ref={box}
            className="pointer-events-none absolute inset-x-8 top-(--gap-y) h-0"
            style={{ '--gap-y': `${gap?.y ?? 0}px` } as CSSProperties}
        >
            {gap !== null && (
                <>
                    <span className="bg-accent/35 absolute inset-x-0 -top-px h-0.5" />
                    <Button
                        type="button"
                        size="icon-xs"
                        aria-label={__('Add a block here')}
                        title={__('Add a block here')}
                        onClick={(event) => onAdd(gap.at, event.currentTarget)}
                        className="pointer-events-auto absolute top-0 left-1/2 size-6 -translate-1/2 rounded-full"
                    >
                        <Plus />
                    </Button>
                </>
            )}
        </div>
    );
}

/**
 * The article's body: the toolbar, the writing surface, the selection and
 * slash menus, and a word count at its foot. Everything the editor can do is
 * on the toolbar, in + Insert, or both; the slash menu is an accelerator
 * (I-185). The cards read their media from the CardEnvironmentContext the
 * page provides, which the rail's inspector shares.
 */
export function ProseEditor({
    document,
    cards,
    menuCards,
    contentType,
    status,
    permissions,
    spellcheck,
    smartQuotes,
    siteUrl,
    onChange,
    onEditor,
}: {
    document: EditorDocument;
    cards: CardManifestEntry[];
    /**
     * The cards the insert and slash menus offer, when fewer than the schema
     * holds: a theme region's allowed cards. The schema keeps every card, so
     * a pasted one the region doesn't allow reaches the server, which says why.
     */
    menuCards?: CardManifestEntry[];
    contentType: string;
    status: string;
    permissions: Record<string, boolean>;
    /** The person's editor.spellcheck. */
    spellcheck: boolean;
    /** The person's editor.smart_quotes. */
    smartQuotes: boolean;
    /** The site's address, so a link to the site is stored by ID or path (rule 9). */
    siteUrl: string;
    onChange: (document: JSONContent) => void;
    /** The editor, once it exists, for the rail and the checks to use. */
    onEditor: (editor: Editor | null) => void;
}) {
    const embedProviders = useCardEnvironment().manifest.embedProviders;
    const editor = useTemeEditor({
        document,
        cards,
        embedProviders,
        spellcheck,
        smartQuotes,
        siteUrl,
        onChange,
    });

    useEffect(() => {
        onEditor(editor);

        return () => onEditor(null);
    }, [editor, onEditor]);

    const context = useMemo<EditorContext | null>(
        () =>
            editor === null
                ? null
                : {
                      editor,
                      contentType,
                      status,
                      can: (permission) => permissions[permission] ?? false,
                      selection: {
                          empty: editor.state.selection.empty,
                          cardName: null,
                      },
                  },
        [editor, contentType, status, permissions],
    );

    // What opened the block picker, when it is open from "+" or Insert.
    const [picker, setPicker] = useState<PickerRequest | null>(null);
    const closePicker = useCallback(() => setPicker(null), []);
    const shown = menuCards ?? cards;

    const addAfter = useCallback(
        (block: TopBlock, button: HTMLElement) =>
            setPicker({
                from: 'after',
                at: block.pos + block.node.nodeSize,
                name: blockName(block.node, cards),
                anchor: button.getBoundingClientRect(),
                opener: button,
            }),
        [cards],
    );
    const addHere = useCallback(
        (at: number, button: HTMLElement) =>
            setPicker({
                from: 'here',
                at,
                anchor: button.getBoundingClientRect(),
                opener: button,
            }),
        [],
    );
    const insertAtCursor = useCallback(
        (button: HTMLElement) =>
            setPicker({
                from: 'cursor',
                anchor: button.getBoundingClientRect(),
                opener: button,
            }),
        [],
    );

    if (editor === null || context === null) {
        return <div className="min-h-60" aria-busy="true" />;
    }

    return (
        <div className="grid grid-cols-1">
            <Toolbar
                editor={editor}
                siteUrl={siteUrl}
                onInsert={insertAtCursor}
            />
            <PastedImagesBar editor={editor} />
            <div className="relative">
                <EditorContent editor={editor} />
                <BlockHandles editor={editor} cards={cards} onAdd={addAfter} />
                <BetweenBlocks editor={editor} onAdd={addHere} />
            </div>
            <BubbleMenu editor={editor} siteUrl={siteUrl} />
            <BlockPicker
                editor={editor}
                cards={shown}
                context={context}
                request={picker}
                onClose={closePicker}
            />
            <WordCount editor={editor} />
        </div>
    );
}
