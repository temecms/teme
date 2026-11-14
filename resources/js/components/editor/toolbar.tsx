// Later (I-200): extensions contribute entries to this list in phase 5.
// Core draws them, so they look like the rest of the editor; the region
// shows a fixed number and puts the rest under "More".
//
// The toolbar itself holds core formats only: it is the one region plugins
// never add to (plugins.md, surface 3). Their buttons go in the selection
// bubble's More group.
import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    ChevronDown,
    Code,
    Highlighter,
    Italic,
    Link as LinkIcon,
    List,
    ListChecks,
    ListOrdered,
    MoreHorizontal,
    Plus,
    Quote,
    Redo2,
    RemoveFormatting,
    Strikethrough,
    Subscript as SubscriptIcon,
    Superscript as SuperscriptIcon,
    Table as TableIcon,
    Underline as UnderlineIcon,
    Undo2,
} from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { ComponentProps, KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { CardManifestEntry } from '@/types';
import { cardEntries } from './insert-entries';
import { LinkPopover } from './link-popover';
import type { EditorContext } from './region-entry';
import { matches } from './region-entry';

/** A 32px toolbar button: ghost, at full strength, its pressed state the design system's. */
function ToolButton({
    label,
    active = false,
    className,
    children,
    ...props
}: ComponentProps<'button'> & { label: string; active?: boolean }) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={label}
            aria-pressed={active}
            title={label}
            className={cn('shrink-0', className)}
            {...props}
        >
            {children}
        </Button>
    );
}

// In a narrow row the dividers go and Insert and More show only their icons
// (the @container on the row).
function Divider() {
    return (
        <span
            aria-hidden
            className="bg-line mx-1 h-5 w-px shrink-0 @max-lg:hidden"
        />
    );
}

/** The block types the toolbar's first control turns the current block into, looked up while drawing. */
function blockTypes(): { value: string; label: string }[] {
    return [
        { value: 'paragraph', label: __('Paragraph') },
        { value: 'heading-2', label: __('Heading 2') },
        { value: 'heading-3', label: __('Heading 3') },
        { value: 'heading-4', label: __('Heading 4') },
        { value: 'blockquote', label: __('Quote') },
        { value: 'codeBlock', label: __('Code block') },
    ];
}

// The order controls fold into More when the row is too narrow. Bold,
// italic, link and + Insert are not here: they never fold. Everything else
// must, or a phone-width row clips More itself (B-138).
const FOLD_ORDER = [
    'table',
    'blockquote',
    'taskList',
    'orderedList',
    'bulletList',
    'strike',
    'underline',
    'redo',
    'undo',
    'text',
] as const;
type Foldable = (typeof FOLD_ORDER)[number];

// Typing in a menu's filter must not move the menu's own selection.
const keepTyping = (event: KeyboardEvent) => event.stopPropagation();

/**
 * The editor's toolbar: one sticky row that never wraps and never scrolls
 * sideways (I-231, I-235, I-238). When it does not fit, a ResizeObserver
 * folds controls one at a time into More, and unfolds them when there is
 * room again.
 */
export function Toolbar({
    editor,
    cards,
    context,
    siteUrl,
}: {
    editor: Editor;
    cards: CardManifestEntry[];
    context: EditorContext;
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
            subscript: current.isActive('subscript'),
            superscript: current.isActive('superscript'),
            link: current.isActive('link'),
            bulletList: current.isActive('bulletList'),
            orderedList: current.isActive('orderedList'),
            taskList: current.isActive('taskList'),
            blockquote: current.isActive('blockquote'),
            codeBlock: current.isActive('codeBlock'),
            table: current.isActive('table'),
            textAlign: (['left', 'center', 'right', 'justify'] as const).find(
                (align) => current.isActive({ textAlign: align }),
            ),
            blockType: current.isActive('heading', { level: 2 })
                ? 'heading-2'
                : current.isActive('heading', { level: 3 })
                  ? 'heading-3'
                  : current.isActive('heading', { level: 4 })
                    ? 'heading-4'
                    : current.isActive('codeBlock')
                      ? 'codeBlock'
                      : current.isActive('blockquote')
                        ? 'blockquote'
                        : 'paragraph',
            undo: current.can().undo(),
            redo: current.can().redo(),
        }),
    });

    const row = useRef<HTMLDivElement>(null);
    const inner = useRef<HTMLDivElement>(null);
    const widths = useRef<Partial<Record<Foldable, number>>>({});
    const [folded, setFolded] = useState(0);
    const [filter, setFilter] = useState('');

    // Fold one control at a time while the row overflows; unfold one when the
    // next would fit again.
    useLayoutEffect(() => {
        const box = row.current;
        const content = inner.current;

        if (!box || !content) {
            return;
        }

        const measure = () => {
            for (const id of FOLD_ORDER) {
                const element = content.querySelector<HTMLElement>(
                    `[data-fold="${id}"]`,
                );

                if (element) {
                    widths.current[id] = element.offsetWidth;
                }
            }

            // clientWidth includes the row's padding, which is not room.
            const style = getComputedStyle(box);
            const free =
                box.clientWidth -
                parseFloat(style.paddingLeft) -
                parseFloat(style.paddingRight) -
                content.offsetWidth;

            if (free < 0 && folded < FOLD_ORDER.length) {
                setFolded((count) => count + 1);
            } else if (folded > 0) {
                const next = FOLD_ORDER[folded - 1];

                if (free >= (widths.current[next] ?? 40) + 8) {
                    setFolded((count) => count - 1);
                }
            }
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(box);

        return () => observer.disconnect();
    }, [folded]);

    const isFolded = (id: Foldable) => FOLD_ORDER.indexOf(id) < folded;
    const chain = () => editor.chain().focus();

    const setBlockType = (value: string) => {
        if (value === 'paragraph') {
            chain().setParagraph().run();
        } else if (value.startsWith('heading-')) {
            chain()
                .setHeading({ level: Number(value.slice(8)) as 2 | 3 | 4 })
                .run();
        } else if (value === 'blockquote') {
            chain().setParagraph().toggleBlockquote().run();
        } else if (value === 'codeBlock') {
            chain().setCodeBlock().run();
        }
    };

    const insertTable = () =>
        state.table
            ? chain().deleteTable().run()
            : chain()
                  .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
                  .run();

    // The controls that fold, with what More shows for each. The Aa menu
    // folds too, and shows in More as a submenu.
    const foldable: Record<
        Exclude<Foldable, 'text'>,
        {
            label: string;
            run: () => void;
            disabled?: boolean;
            button: ReactNode;
        }
    > = {
        undo: {
            label: __('Undo'),
            run: () => chain().undo().run(),
            disabled: !state.undo,
            button: (
                <ToolButton
                    label={__('Undo')}
                    disabled={!state.undo}
                    onClick={() => chain().undo().run()}
                >
                    <Undo2 />
                </ToolButton>
            ),
        },
        redo: {
            label: __('Redo'),
            run: () => chain().redo().run(),
            disabled: !state.redo,
            button: (
                <ToolButton
                    label={__('Redo')}
                    disabled={!state.redo}
                    onClick={() => chain().redo().run()}
                >
                    <Redo2 />
                </ToolButton>
            ),
        },
        underline: {
            label: __('Underline'),
            run: () => chain().toggleUnderline().run(),
            button: (
                <ToolButton
                    label={__('Underline')}
                    active={state.underline}
                    onClick={() => chain().toggleUnderline().run()}
                >
                    <UnderlineIcon />
                </ToolButton>
            ),
        },
        strike: {
            label: __('Strikethrough'),
            run: () => chain().toggleStrike().run(),
            button: (
                <ToolButton
                    label={__('Strikethrough')}
                    active={state.strike}
                    onClick={() => chain().toggleStrike().run()}
                >
                    <Strikethrough />
                </ToolButton>
            ),
        },
        bulletList: {
            label: __('Bulleted list'),
            run: () => chain().toggleBulletList().run(),
            button: (
                <ToolButton
                    label={__('Bulleted list')}
                    active={state.bulletList}
                    onClick={() => chain().toggleBulletList().run()}
                >
                    <List />
                </ToolButton>
            ),
        },
        orderedList: {
            label: __('Numbered list'),
            run: () => chain().toggleOrderedList().run(),
            button: (
                <ToolButton
                    label={__('Numbered list')}
                    active={state.orderedList}
                    onClick={() => chain().toggleOrderedList().run()}
                >
                    <ListOrdered />
                </ToolButton>
            ),
        },
        taskList: {
            label: __('Task list'),
            run: () => chain().toggleTaskList().run(),
            button: (
                <ToolButton
                    label={__('Task list')}
                    active={state.taskList}
                    onClick={() => chain().toggleTaskList().run()}
                >
                    <ListChecks />
                </ToolButton>
            ),
        },
        blockquote: {
            label: __('Quote'),
            run: () => chain().toggleBlockquote().run(),
            button: (
                <ToolButton
                    label={__('Quote')}
                    active={state.blockquote}
                    onClick={() => chain().toggleBlockquote().run()}
                >
                    <Quote />
                </ToolButton>
            ),
        },
        table: {
            label: state.table ? __('Delete table') : __('Table'),
            run: insertTable,
            button: (
                <ToolButton
                    label={
                        state.table ? __('Delete table') : __('Insert a table')
                    }
                    active={state.table}
                    onClick={insertTable}
                >
                    <TableIcon />
                </ToolButton>
            ),
        },
    };

    const fold = (id: Exclude<Foldable, 'text'>) =>
        isFolded(id) ? null : (
            <span data-fold={id} className="inline-flex">
                {foldable[id].button}
            </span>
        );

    // The Aa menu's items, shown in the row's Aa menu or, folded, in More.
    const textItems = (
        <>
            <DropdownMenuLabel>{__('Alignment')}</DropdownMenuLabel>
            <DropdownMenuItem
                onSelect={() => chain().setTextAlign('left').run()}
            >
                <AlignLeft /> {__('Left')}
                {state.textAlign === 'left' ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem
                onSelect={() => chain().setTextAlign('center').run()}
            >
                <AlignCenter /> {__('Center')}
                {state.textAlign === 'center' ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem
                onSelect={() => chain().setTextAlign('right').run()}
            >
                <AlignRight /> {__('Right')}
                {state.textAlign === 'right' ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem
                onSelect={() => chain().setTextAlign('justify').run()}
            >
                <AlignJustify /> {__('Justified')}
                {state.textAlign === 'justify' ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => chain().unsetTextAlign().run()}>
                {__('Default alignment')}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => chain().toggleHighlight().run()}>
                <Highlighter /> {__('Highlight')}
                {state.highlight ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => chain().toggleCode().run()}>
                <Code /> {__('Inline code')}
                {state.code ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => chain().toggleSubscript().run()}>
                <SubscriptIcon /> {__('Subscript')}
                {state.subscript ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuItem
                onSelect={() => chain().toggleSuperscript().run()}
            >
                <SuperscriptIcon /> {__('Superscript')}
                {state.superscript ? ' ✓' : ''}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                onSelect={() => chain().unsetAllMarks().clearNodes().run()}
            >
                <RemoveFormatting /> {__('Clear formatting')}
            </DropdownMenuItem>
        </>
    );

    const entries = cardEntries(cards).filter((entry) =>
        matches(entry, filter),
    );
    const showRule = 'horizontal rule divider line'.includes(
        filter.trim().toLowerCase(),
    );

    const insert = (
        <DropdownMenu onOpenChange={() => setFilter('')}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="shrink-0 gap-1 px-2"
                >
                    <Plus />
                    <span className="@max-lg:sr-only">{__('Insert')}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-80 max-w-(--radix-dropdown-menu-content-available-width)"
            >
                <div className="p-1">
                    <Input
                        aria-label={__('Filter what to insert')}
                        placeholder={__('Filter')}
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                        onKeyDown={keepTyping}
                    />
                </div>
                {entries.map((entry) => (
                    <DropdownMenuItem
                        key={entry.id}
                        onSelect={() => entry.run(context)}
                        className="flex-col items-start gap-0"
                    >
                        <span className="font-medium">{entry.label}</span>
                        {entry.description && (
                            <span className="text-ink-3 text-small">
                                {entry.description}
                            </span>
                        )}
                    </DropdownMenuItem>
                ))}
                {showRule && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() =>
                                chain()
                                    .insertContent({ type: 'horizontalRule' })
                                    .run()
                            }
                            className="flex-col items-start gap-0"
                        >
                            <span className="font-medium">
                                {__('Horizontal rule')}
                            </span>
                            <span className="text-ink-3 text-small">
                                {__(
                                    'A line across the column, between two parts of the text.',
                                )}
                            </span>
                        </DropdownMenuItem>
                    </>
                )}
                {entries.length === 0 && !showRule && (
                    <p className="text-ink-3 text-small px-2 py-1">
                        {__('Nothing matches.')}
                    </p>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <div
            ref={row}
            role="toolbar"
            aria-label={__('Formatting')}
            className="bg-page/86 @container sticky top-0 z-(--z-toolbar) overflow-hidden rounded-lg px-2 py-1 backdrop-blur-sm"
        >
            <div
                ref={inner}
                className="inline-flex items-center gap-0.5 whitespace-nowrap"
            >
                <SelectField
                    aria-label={__('Block type')}
                    value={state.blockType}
                    onValueChange={setBlockType}
                    options={blockTypes()}
                    className="text-small h-(--ctl-h-sm) w-33 overflow-hidden border-transparent bg-transparent *:data-[slot=select-value]:block *:data-[slot=select-value]:min-w-0 *:data-[slot=select-value]:truncate @max-lg:w-26 @max-xs:w-21"
                />
                <Divider />
                <ToolButton
                    label={__('Bold')}
                    active={state.bold}
                    onClick={() => chain().toggleBold().run()}
                >
                    <Bold />
                </ToolButton>
                <ToolButton
                    label={__('Italic')}
                    active={state.italic}
                    onClick={() => chain().toggleItalic().run()}
                >
                    <Italic />
                </ToolButton>
                {fold('underline')}
                {fold('strike')}
                <Divider />
                {fold('bulletList')}
                {fold('orderedList')}
                {fold('taskList')}
                {fold('blockquote')}
                <LinkPopover
                    editor={editor}
                    siteUrl={siteUrl}
                    trigger={
                        <ToolButton label={__('Link')} active={state.link}>
                            <LinkIcon />
                        </ToolButton>
                    }
                />
                {fold('table')}
                <Divider />
                {insert}
                {!isFolded('text') && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-fold="text"
                                aria-label={__(
                                    'Text: alignment, highlight and more',
                                )}
                                title={__(
                                    'Text: alignment, highlight and more',
                                )}
                                className="shrink-0 gap-0.5 px-2"
                            >
                                {__('Aa')}
                                <ChevronDown />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {textItems}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {fold('undo')}
                {fold('redo')}
                {folded > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={__('More')}
                                title={__('More')}
                                className="shrink-0 gap-1 px-2"
                            >
                                <MoreHorizontal />
                                <span className="@max-lg:sr-only">
                                    {__('More')}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {isFolded('text') && (
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>
                                        {__('Text')}
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        {textItems}
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            )}
                            {FOLD_ORDER.slice(0, folded)
                                .reverse()
                                .filter((id) => id !== 'text')
                                .map((id) => (
                                    <DropdownMenuItem
                                        key={id}
                                        disabled={foldable[id].disabled}
                                        onSelect={foldable[id].run}
                                    >
                                        {foldable[id].label}
                                    </DropdownMenuItem>
                                ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </div>
    );
}
