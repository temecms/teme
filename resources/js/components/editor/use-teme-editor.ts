import { Extension } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { useEditor } from '@tiptap/react';
import type { Editor, JSONContent } from '@tiptap/react';
import { useEffect, useRef, useState } from 'react';
import { ulid } from '@/lib/ulid';
import type { CardManifestEntry, EditorDocument, EmbedProvider } from '@/types';
import { currentBlock, duplicateBlock, moveBlock } from './block-actions';
import { FOCUS_GRIP } from './block-handle';
import { withBlockIds } from './block-ids';
import { EDIT_LINK } from './link-popover';
import { cleanPastedHtml, embedFromPastedText } from './paste';
import { PastedImageWithView } from './pasted-image-view';
import { hasPastedImage, withoutPastedImages } from './pasted-images';
import { temeExtensions } from './schema';

/**
 * The stored document, with an empty paragraph when it has no blocks. The
 * server stores a new article as `content: []`, and ProseMirror's starting
 * selection in a document with no blocks selects everything, so the first
 * keystroke would replace the article and the selection menu would show.
 */
function withBlock(document: EditorDocument): JSONContent {
    return (document.content?.length ?? 0) > 0
        ? (document as JSONContent)
        : { ...document, content: [{ type: 'paragraph' }] };
}

/**
 * The block keys: Alt Shift ↑ and ↓ move the current block, Mod D
 * duplicates it, Mod K opens the link editor, and Tab outside a list, a table or code reaches the
 * block's handle (the handle shows Shift Tab the way back).
 */
const BlockKeys = Extension.create({
    name: 'temeBlockKeys',

    addKeyboardShortcuts() {
        const block = () => currentBlock(this.editor);

        return {
            'Alt-Shift-ArrowUp': () => {
                const at = block();

                return at !== null && moveBlock(this.editor, at, -1);
            },
            'Alt-Shift-ArrowDown': () => {
                const at = block();

                return at !== null && moveBlock(this.editor, at, 1);
            },
            'Mod-d': () => {
                const at = block();

                return at !== null && duplicateBlock(this.editor, at);
            },
            Tab: () => {
                const { editor } = this;

                if (
                    editor.isActive('listItem') ||
                    editor.isActive('taskItem') ||
                    editor.isActive('table') ||
                    editor.isActive('codeBlock')
                ) {
                    return false;
                }

                // True when the handle took focus.
                return !editor.view.dom.dispatchEvent(
                    new Event(FOCUS_GRIP, { cancelable: true }),
                );
            },
        };
    },

    addProseMirrorPlugins() {
        return [
            new Plugin({
                props: {
                    // Ctrl K (⌘ K) in the text: the link editor, from the bubble or the
                    // toolbar. Once it opens, the key goes no further, so the admin's
                    // palette, which also answers Ctrl K, stays shut.
                    handleKeyDown: (view, event) => {
                        if (
                            !(event.metaKey || event.ctrlKey) ||
                            event.altKey ||
                            event.key.toLowerCase() !== 'k'
                        ) {
                            return false;
                        }

                        const opened = !view.dom.dispatchEvent(
                            new Event(EDIT_LINK, { cancelable: true }),
                        );

                        if (opened) {
                            event.stopPropagation();
                        }

                        return opened;
                    },
                },
            }),
        ];
    },
});

/**
 * The editor for one article's body: Teme's extensions, the stored document,
 * and a callback with the JSON whenever it changes. It never re-renders on
 * every transaction (study L §7); the toolbar reads its state through
 * selectors instead. A paste from Word or Google Docs is cleaned, and a video
 * or audio address pasted on an empty line becomes an embed card (I-426). An
 * image pasted from another site waits in place to be imported into the media
 * library, and what is saved leaves it out until it is (I-453). Every block
 * is given its ID before the editor opens, so opening is no change (I-3362).
 */
export function useTemeEditor({
    document,
    cards,
    embedProviders,
    spellcheck,
    smartQuotes,
    siteUrl,
    onChange,
}: {
    document: EditorDocument;
    cards: CardManifestEntry[];
    embedProviders: EmbedProvider[];
    /** The person's editor.spellcheck. */
    spellcheck: boolean;
    /** The person's editor.smart_quotes. */
    smartQuotes: boolean;
    /** The site's address, so a link to the site is stored by ID or path (rule 9). */
    siteUrl: string;
    onChange: (document: JSONContent) => void;
}): Editor | null {
    // The latest callback and providers, without rebuilding the editor when
    // they change: useEditor reads its options once.
    const changed = useRef(onChange);
    const providers = useRef(embedProviders);
    const site = useRef(siteUrl);

    useEffect(() => {
        changed.current = onChange;
        providers.current = embedProviders;
        site.current = siteUrl;
    });

    // The stored document as the editor opens it, made once (I-3362).
    const [opened] = useState(() =>
        withBlockIds(
            withBlock(document),
            cards.map((card) => card.name),
        ),
    );

    return useEditor({
        extensions: [
            ...temeExtensions(cards, { smartQuotes }),
            PastedImageWithView,
            BlockKeys,
        ],
        content: opened,
        immediatelyRender: false,
        shouldRerenderOnTransaction: false,
        editorProps: {
            attributes: {
                class: 'teme-prose min-h-60 px-8 py-4 outline-none',
                'aria-labelledby': 'editor-body-label',
                'aria-multiline': 'true',
                role: 'textbox',
                spellcheck: spellcheck ? 'true' : 'false',
            },
            transformPastedHTML: (html) => cleanPastedHtml(html, site.current),
            handlePaste: (view, event) => {
                const { selection, schema } = view.state;
                const { $from } = selection;
                const inEmptyParagraph =
                    selection.empty &&
                    $from.parent.type.name === 'paragraph' &&
                    $from.parent.content.size === 0;
                const attrs = inEmptyParagraph
                    ? embedFromPastedText(
                          event.clipboardData?.getData('text/plain') ?? '',
                          providers.current,
                      )
                    : null;

                if (attrs === null || schema.nodes.embed === undefined) {
                    // Not ours: ProseMirror pastes, and Tiptap's link
                    // extension makes a pasted address a link.
                    return false;
                }

                view.dispatch(
                    view.state.tr.replaceWith(
                        $from.before(),
                        $from.after(),
                        schema.nodes.embed.create({ ...attrs, id: ulid() }),
                    ),
                );

                return true;
            },
        },
        // A pasted image waiting to be imported is never saved (I-453).
        onUpdate: ({ editor }) =>
            changed.current(
                hasPastedImage(editor.state.doc)
                    ? withoutPastedImages(editor.getJSON())
                    : editor.getJSON(),
            ),
    });
}
