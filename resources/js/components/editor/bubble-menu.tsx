// Later (I-200): extensions contribute entries to the bubble's More group.
import { Content as MenuContent } from '@radix-ui/react-dropdown-menu';
import { isNodeSelection } from '@tiptap/core';
import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import { BubbleMenu as TiptapBubbleMenu } from '@tiptap/react/menus';
import {
    Bold,
    Code,
    Italic,
    Link as LinkIcon,
    MoreHorizontal,
    RemoveFormatting,
    Strikethrough,
    Subscript as SubscriptIcon,
    Superscript as SuperscriptIcon,
    Underline as UnderlineIcon,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/lib/i18n';
import { EDIT_LINK, LinkPanel } from './link-popover';
import { RewriteMenu } from './rewrite/rewrite-menu';
import { isTouch } from './use-touch';

/** The selection bubble's plugin key, by which it is told to show. */
const BUBBLE = 'temeSelectionBubble';

function MarkButton({
    label,
    active,
    ...props
}: ComponentProps<'button'> & { label: string; active: boolean }) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={label}
            aria-pressed={active}
            title={label}
            {...props}
        />
    );
}

/**
 * The menu on a text selection (docs/architecture/design-system.md, 4.39):
 * Bold, Italic, Strikethrough and Inline code, the link, and More with the
 * rest of the core marks, then Rewrite when the editor offers it. Link,
 * or Ctrl K, puts the link editor in place of the buttons. A
 * plugin's selection buttons will arrive in More's "From plugins" group
 * (plugins.md, surface 3); the toolbar never takes them. On touch it does
 * not open: the toolbar has the formats, and the system's own text menu is
 * left alone.
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
            superscript: current.isActive('superscript'),
            subscript: current.isActive('subscript'),
            link: current.isActive('link'),
        }),
    });
    const chain = () => editor.chain().focus();
    // The link editor in place of the buttons; read where the bubble decides whether to show.
    const [linking, setLinking] = useState(false);
    const linkingRef = useRef(false);
    linkingRef.current = linking;

    // The link editor, at once: the bubble shows a moment after a selection
    // settles, and the panel's focus needs it on the page.
    const startLinking = useCallback(() => {
        setLinking(true);
        editor.view.dispatch(editor.state.tr.setMeta(BUBBLE, 'show'));
    }, [editor]);

    // Ctrl K on selected text.
    useEffect(() => {
        const dom = editor.view.dom;
        const asked = (event: Event): void => {
            if (
                !event.defaultPrevented &&
                !editor.state.selection.empty &&
                !isTouch()
            ) {
                event.preventDefault();
                startLinking();
            }
        };

        // Back in the text: the buttons again.
        const back = (): void => setLinking(false);

        dom.addEventListener(EDIT_LINK, asked);
        editor.on('focus', back);

        return () => {
            dom.removeEventListener(EDIT_LINK, asked);
            editor.off('focus', back);
        };
    }, [editor, startLinking]);

    // One function for the bubble's life: a new one would register the bubble
    // again, taking it off the page for a moment.
    const shouldShow = useCallback<
        NonNullable<ComponentProps<typeof TiptapBubbleMenu>['shouldShow']>
    >(
        ({ editor: current, element, view, state, from, to }) =>
            linkingRef.current ||
            (!isTouch() &&
                (view.hasFocus() || element.contains(document.activeElement)) &&
                !isNodeSelection(state.selection) &&
                state.doc.textBetween(from, to).length > 0 &&
                !current.isActive('codeBlock')),
        [],
    );

    return (
        <TiptapBubbleMenu editor={editor} shouldShow={shouldShow}>
            {linking ? (
                <div className="shadow-float relative z-(--z-float) rounded-lg">
                    <LinkPanel
                        editor={editor}
                        siteUrl={siteUrl}
                        onDone={() => setLinking(false)}
                    />
                </div>
            ) : (
                <div
                    role="toolbar"
                    aria-label={__('Selection')}
                    data-slot="bubble-menu"
                    className="bg-surface shadow-float relative z-(--z-float) flex items-center gap-0.5 rounded-lg p-1"
                >
                    <MarkButton
                        label={__('Bold')}
                        active={state.bold}
                        onClick={() => chain().toggleBold().run()}
                    >
                        <Bold />
                    </MarkButton>
                    <MarkButton
                        label={__('Italic')}
                        active={state.italic}
                        onClick={() => chain().toggleItalic().run()}
                    >
                        <Italic />
                    </MarkButton>
                    <MarkButton
                        label={__('Strikethrough')}
                        active={state.strike}
                        onClick={() => chain().toggleStrike().run()}
                    >
                        <Strikethrough />
                    </MarkButton>
                    <MarkButton
                        label={__('Inline code')}
                        active={state.code}
                        onClick={() => chain().toggleCode().run()}
                    >
                        <Code />
                    </MarkButton>
                    <span aria-hidden className="bg-line mx-1 h-5 w-px" />
                    <MarkButton
                        label={__('Link')}
                        active={state.link}
                        onClick={startLinking}
                    >
                        <LinkIcon />
                    </MarkButton>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={__('More')}
                                title={__('More')}
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        {/* In place, not portalled: focus stays inside the selection menu, which hides when it leaves. */}
                        <MenuContent
                            align="end"
                            sideOffset={4}
                            className="bg-surface text-ink shadow-float z-(--z-float) flex min-w-55 flex-col rounded-lg p-1"
                        >
                            <DropdownMenuCheckboxItem
                                checked={state.underline}
                                onSelect={() => chain().toggleUnderline().run()}
                            >
                                <UnderlineIcon />
                                {__('Underline')}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuCheckboxItem
                                checked={state.superscript}
                                onSelect={() =>
                                    chain().toggleSuperscript().run()
                                }
                            >
                                <SuperscriptIcon />
                                {__('Superscript')}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuCheckboxItem
                                checked={state.subscript}
                                onSelect={() => chain().toggleSubscript().run()}
                            >
                                <SubscriptIcon />
                                {__('Subscript')}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuItem
                                onSelect={() => chain().unsetAllMarks().run()}
                            >
                                <RemoveFormatting />
                                {__('Clear formatting')}
                            </DropdownMenuItem>
                            {/* Later (I-200): a plugin's selection buttons, drawn by core under a "From plugins" heading, once there are any. */}
                        </MenuContent>
                    </DropdownMenu>
                    <RewriteMenu editor={editor} />
                </div>
            )}
        </TiptapBubbleMenu>
    );
}
