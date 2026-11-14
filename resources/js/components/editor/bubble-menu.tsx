// Later (I-200): extensions contribute entries to this list in phase 5.
// Core draws them, so they look like the rest of the editor; the region
// shows a fixed number and puts the rest under "More".
import { isNodeSelection } from '@tiptap/core';
import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import { BubbleMenu as TiptapBubbleMenu } from '@tiptap/react/menus';
import {
    Bold,
    Code,
    Highlighter,
    Italic,
    Link as LinkIcon,
    Strikethrough,
    Underline as UnderlineIcon,
} from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
import { LinkPopover } from './link-popover';
import { RewriteMenu } from './rewrite/rewrite-menu';

function MarkButton({
    label,
    active,
    className,
    children,
    ...props
}: ComponentProps<'button'> & { label: string; active: boolean }) {
    return (
        <button
            type="button"
            aria-label={label}
            aria-pressed={active}
            title={label}
            className={cn(
                'hover:bg-accent focus-visible:ring-ring inline-flex size-[30px] items-center justify-center rounded-md outline-none focus-visible:ring-2 [&_svg]:size-4',
                active && 'bg-muted ring-border ring-1 ring-inset',
                className,
            )}
            {...props}
        >
            {children}
        </button>
    );
}

/**
 * The menu on a text selection: the core marks, the link, and Rewrite when
 * the editor offers it. A plugin's
 * selection actions will arrive under a More group here (plugins.md, surface
 * 3); the toolbar never takes them.
 */
export function BubbleMenu({
    editor,
    siteUrl,
}: {
    editor: Editor;
    /** The site's address, for links to the site itself. */
    siteUrl: string;
}) {
    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current.isActive('bold'),
            italic: current.isActive('italic'),
            underline: current.isActive('underline'),
            strike: current.isActive('strike'),
            code: current.isActive('code'),
            highlight: current.isActive('highlight'),
            link: current.isActive('link'),
        }),
    });
    const chain = () => editor.chain().focus();

    return (
        <TiptapBubbleMenu
            editor={editor}
            shouldShow={({ editor: current, element, view, state, from, to }) =>
                (view.hasFocus() || element.contains(document.activeElement)) &&
                !isNodeSelection(state.selection) &&
                state.doc.textBetween(from, to).length > 0 &&
                !current.isActive('codeBlock')
            }
        >
            <div
                role="toolbar"
                aria-label="Selection"
                className="bg-popover border-border relative z-20 flex items-center gap-0.5 rounded-lg border p-1 shadow-(--shadow)"
            >
                <MarkButton
                    label="Bold"
                    active={state.bold}
                    onClick={() => chain().toggleBold().run()}
                >
                    <Bold />
                </MarkButton>
                <MarkButton
                    label="Italic"
                    active={state.italic}
                    onClick={() => chain().toggleItalic().run()}
                >
                    <Italic />
                </MarkButton>
                <MarkButton
                    label="Underline"
                    active={state.underline}
                    onClick={() => chain().toggleUnderline().run()}
                >
                    <UnderlineIcon />
                </MarkButton>
                <MarkButton
                    label="Strikethrough"
                    active={state.strike}
                    onClick={() => chain().toggleStrike().run()}
                >
                    <Strikethrough />
                </MarkButton>
                <MarkButton
                    label="Inline code"
                    active={state.code}
                    onClick={() => chain().toggleCode().run()}
                >
                    <Code />
                </MarkButton>
                <MarkButton
                    label="Highlight"
                    active={state.highlight}
                    onClick={() => chain().toggleHighlight().run()}
                >
                    <Highlighter />
                </MarkButton>
                <LinkPopover
                    editor={editor}
                    siteUrl={siteUrl}
                    trigger={
                        <MarkButton label="Link" active={state.link}>
                            <LinkIcon />
                        </MarkButton>
                    }
                />
                <RewriteMenu editor={editor} />
            </div>
        </TiptapBubbleMenu>
    );
}
