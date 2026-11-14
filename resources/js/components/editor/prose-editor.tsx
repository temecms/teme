import type { Editor } from '@tiptap/core';
import { EditorContent } from '@tiptap/react';
import type { JSONContent } from '@tiptap/react';
import { useEffect, useMemo } from 'react';
import type { CardManifestEntry, EditorDocument } from '@/types';
import { BubbleMenu } from './bubble-menu';
import { useCardEnvironment } from './cards/card-environment';
import { PastedImagesBar } from './pasted-image-view';
import { WordCount } from './rail/document-panel';
import type { EditorContext } from './region-entry';
import { SlashMenu } from './slash-menu';
import { Toolbar } from './toolbar';
import { useTemeEditor } from './use-teme-editor';

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

    if (editor === null || context === null) {
        return <div className="min-h-60" aria-busy="true" />;
    }

    return (
        <div className="grid grid-cols-[minmax(0,1fr)]">
            <Toolbar
                editor={editor}
                cards={menuCards ?? cards}
                context={context}
                siteUrl={siteUrl}
            />
            <PastedImagesBar editor={editor} />
            <EditorContent editor={editor} />
            <BubbleMenu editor={editor} siteUrl={siteUrl} />
            <SlashMenu
                editor={editor}
                cards={menuCards ?? cards}
                context={context}
            />
            <WordCount editor={editor} />
        </div>
    );
}
