import { Content as MenuContent } from '@radix-ui/react-dropdown-menu';
import type { Editor } from '@tiptap/core';
import { NodeSelection } from '@tiptap/pm/state';
import { DragHandle } from '@tiptap/extension-drag-handle-react';
import type { DragHandleProps } from '@tiptap/extension-drag-handle-react';
import {
    ArrowDown,
    ArrowUp,
    Copy,
    GripVertical,
    Plus,
    Repeat2,
    Trash2,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { CSSProperties, RefObject } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/lib/i18n';
import type { CardManifestEntry } from '@/types';
import {
    blockAt,
    blockName,
    canTurn,
    currentBlock,
    deleteBlock,
    duplicateBlock,
    moveBlock,
    turnInto,
} from './block-actions';
import type { TopBlock, TurnInto } from './block-actions';
import { isTouch } from './use-touch';

/** Asked of the handles when Tab leaves the text: show the current block's handle and focus its grip. */
export const FOCUS_GRIP = 'teme:focus-grip';

/** The nearest ancestor that scrolls: the writing column, whose edge the handle never passes. */
function scrollerOf(element: Element): Element {
    for (let at = element.parentElement; at !== null; at = at.parentElement) {
        if (getComputedStyle(at).overflowY !== 'visible') {
            return at;
        }
    }

    return element;
}

/** The handle sits before the block, 4 pixels off, and never past the writing column's edge. */
function gutterLeft(
    handle: HTMLElement,
    block: Element,
    parent: Element,
): number {
    const origin = parent.getBoundingClientRect().left;
    const wanted =
        block.getBoundingClientRect().left - origin - 4 - handle.offsetWidth;

    return Math.max(
        wanted,
        scrollerOf(parent).getBoundingClientRect().left - origin,
    );
}

/** Keeps the pointer's handle inside the column, like the keyboard's. */
const POSITION: DragHandleProps['computePositionConfig'] = {
    placement: 'left-start',
    strategy: 'absolute',
    middleware: [
        {
            name: 'teme-gutter',
            fn: ({ x, y, elements }) => {
                const floating = elements.floating as HTMLElement;
                const parent = floating.offsetParent;

                if (parent === null) {
                    return { x: x - 4, y };
                }

                const min =
                    scrollerOf(floating).getBoundingClientRect().left -
                    parent.getBoundingClientRect().left;

                return { x: Math.max(x - 4, min), y };
            },
        },
    ],
};

const TURN_INTO: { kind: TurnInto; label: () => string }[] = [
    { kind: 'paragraph', label: () => __('Paragraph') },
    { kind: 'heading', label: () => __('Heading') },
    { kind: 'subheading', label: () => __('Subheading') },
    { kind: 'quote', label: () => __('Quote') },
    { kind: 'bulletList', label: () => __('Bulleted list') },
    { kind: 'orderedList', label: () => __('Numbered list') },
];

/** Duplicate's keys, as this computer names them. */
const DUPLICATE_KEYS =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform)
        ? '⌘D'
        : 'Ctrl D';

const HANDLE_BUTTON =
    'text-ink-3 hover:bg-surface-3 pointer-coarse:size-(--ctl-h)';

/**
 * The "+" and the grip for one block, and the block menu the grip opens:
 * Turn into, Duplicate, Move up, Move down and Delete.
 */
function HandleButtons({
    editor,
    block,
    cards,
    source,
    gripRef,
    onAdd,
    onMenu,
}: {
    editor: Editor;
    block: TopBlock | null;
    cards: CardManifestEntry[];
    source: 'pointer' | 'selection';
    gripRef?: RefObject<HTMLButtonElement | null>;
    onAdd: (block: TopBlock) => void;
    onMenu: (open: boolean) => void;
}) {
    const [open, setOpen] = useState(false);
    // The block the menu was opened for: the pointer may move on while it is open.
    const [menuFor, setMenuFor] = useState<TopBlock | null>(null);
    const acted = useRef(false);
    const grip = useRef<HTMLButtonElement | null>(null);
    const name = block === null ? '' : blockName(block.node, cards);
    const target = menuFor ?? block;
    const last = editor.state.doc.childCount - 1;

    const change = (next: boolean): void => {
        setOpen(next);
        setMenuFor(next ? block : null);
        onMenu(next);
    };

    // Runs an action on the block the menu is for, after the menu has closed.
    const act = (run: (target: TopBlock) => boolean): void => {
        if (target === null) {
            return;
        }

        acted.current = true;
        const fresh = blockAt(editor, target.pos) ?? target;
        run(fresh);
    };

    return (
        <div
            data-test="block-handle"
            data-source={source}
            className="flex items-center pointer-coarse:flex-col"
        >
            <Button
                type="button"
                variant="ghost"
                size="icon-xs"
                className={HANDLE_BUTTON}
                aria-label={__('Add a block below')}
                title={__('Add a block below')}
                onClick={() => block !== null && onAdd(block)}
            >
                <Plus />
            </Button>
            <DropdownMenu open={open} onOpenChange={change} modal={false}>
                {/* The grip drags as well as opening the menu, so it is not the menu's trigger: a trigger takes the pointer as it goes down, and a drag never starts. The menu hangs from this anchor and opens on a click or a key. */}
                <div className="relative">
                    <DropdownMenuTrigger asChild>
                        <span
                            aria-hidden
                            className="pointer-events-none absolute inset-0"
                        />
                    </DropdownMenuTrigger>
                    <Button
                        ref={(element) => {
                            grip.current = element;

                            if (gripRef !== undefined) {
                                gripRef.current = element;
                            }
                        }}
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        className={`${HANDLE_BUTTON} cursor-grab`}
                        aria-label={__('Block menu: :name', { name })}
                        aria-haspopup="menu"
                        aria-expanded={open}
                        title={__('Block menu: :name', { name })}
                        data-block-grip={source}
                        onClick={() => change(true)}
                        onKeyDown={(event) => {
                            if (event.key === 'Tab' && event.shiftKey) {
                                event.preventDefault();
                                editor.commands.focus();
                            } else if (
                                ['Enter', ' ', 'ArrowDown'].includes(event.key)
                            ) {
                                event.preventDefault();
                                change(true);
                            }
                        }}
                    >
                        <GripVertical />
                    </Button>
                </div>
                {/* Drawn in place, beside the block, as the bubble's More is. */}
                <MenuContent
                    side="bottom"
                    align="start"
                    sideOffset={4}
                    className="bg-surface text-ink shadow-float z-(--z-float) flex w-56 flex-col rounded-lg p-1"
                    onCloseAutoFocus={(event) => {
                        // Back to the text after an action, else to the grip, never to the anchor.
                        event.preventDefault();

                        if (acted.current) {
                            acted.current = false;
                            editor.view.focus();
                        } else {
                            grip.current?.focus();
                        }
                    }}
                >
                    {target !== null && canTurn(target) && (
                        <>
                            <DropdownMenuSub>
                                <DropdownMenuSubTrigger>
                                    <Repeat2 />
                                    {__('Turn into')}
                                </DropdownMenuSubTrigger>
                                <DropdownMenuSubContent>
                                    {TURN_INTO.map((item) => (
                                        <DropdownMenuItem
                                            key={item.kind}
                                            onSelect={() =>
                                                act((at) =>
                                                    turnInto(
                                                        editor,
                                                        at,
                                                        item.kind,
                                                    ),
                                                )
                                            }
                                        >
                                            {item.label()}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSeparator />
                        </>
                    )}
                    <DropdownMenuItem
                        onSelect={() => act((at) => duplicateBlock(editor, at))}
                    >
                        <Copy />
                        {__('Duplicate')}
                        <DropdownMenuShortcut>
                            {DUPLICATE_KEYS}
                        </DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={target === null || target.index === 0}
                        onSelect={() => act((at) => moveBlock(editor, at, -1))}
                    >
                        <ArrowUp />
                        {__('Move up')}
                        <DropdownMenuShortcut>⌥⇧↑</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={target === null || target.index >= last}
                        onSelect={() => act((at) => moveBlock(editor, at, 1))}
                    >
                        <ArrowDown />
                        {__('Move down')}
                        <DropdownMenuShortcut>⌥⇧↓</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => act((at) => deleteBlock(editor, at))}
                    >
                        <Trash2 />
                        {__('Delete')}
                    </DropdownMenuItem>
                </MenuContent>
            </DropdownMenu>
        </div>
    );
}

/**
 * The handle before each block (docs/architecture/design-system.md, 4.39):
 * "+" adds a block below, and the grip drags the block or opens its menu.
 * Tiptap's drag handle follows the pointer, and hides as a key is pressed;
 * so a second handle, in the same gutter with the same buttons, follows
 * the caret while the last input was a key or a touch, on a selected card,
 * and always for the current block on touch. Tab from the text reaches its
 * grip outside a list, and Shift Tab returns.
 */
export function BlockHandles({
    editor,
    cards,
    onAdd,
}: {
    editor: Editor;
    cards: CardManifestEntry[];
    onAdd: (block: TopBlock) => void;
}) {
    const [hovered, setHovered] = useState<TopBlock | null>(null);
    const [current, setCurrent] = useState<TopBlock | null>(null);
    const [byKey, setByKey] = useState(false);
    const [focused, setFocused] = useState(false);
    const [forced, setForced] = useState(false);
    // Each Tab from the text that asks for the grip.
    const [focusAsked, setFocusAsked] = useState(0);
    const [menuOpen, setMenuOpen] = useState(false);
    const [at, setAt] = useState<{ x: number; y: number } | null>(null);
    const box = useRef<HTMLDivElement>(null);
    const handle = useRef<HTMLDivElement>(null);
    const grip = useRef<HTMLButtonElement>(null);
    const dragged = useRef<HTMLElement | null>(null);

    // The current block, kept as the selection moves.
    useEffect(() => {
        const update = (): void => setCurrent(currentBlock(editor));
        const focus = (): void => setFocused(true);
        const blur = (): void => {
            // Focus moving into the handle keeps it.
            requestAnimationFrame(() => {
                const inside =
                    handle.current?.contains(document.activeElement) ?? false;
                setFocused(editor.view.hasFocus() || inside);

                if (!inside && !editor.view.hasFocus()) {
                    setForced(false);
                }
            });
        };

        update();
        editor.on('selectionUpdate', update);
        editor.on('update', update);
        editor.on('focus', focus);
        editor.on('blur', blur);

        return () => {
            editor.off('selectionUpdate', update);
            editor.off('update', update);
            editor.off('focus', focus);
            editor.off('blur', blur);
        };
    }, [editor]);

    // Which way the person last moved: a key or a touch, or a mouse.
    useEffect(() => {
        const key = (): void => setByKey(true);
        const pointer = (event: PointerEvent): void =>
            setByKey(
                event.pointerType === 'touch' || event.pointerType === 'pen',
            );

        document.addEventListener('keydown', key, true);
        document.addEventListener('pointerdown', pointer, true);

        return () => {
            document.removeEventListener('keydown', key, true);
            document.removeEventListener('pointerdown', pointer, true);
        };
    }, []);

    // Tab from the text, outside a list: the grip.
    useEffect(() => {
        const dom = editor.view.dom;
        const asked = (event: Event): void => {
            event.preventDefault();
            setForced(true);
            setFocusAsked((count) => count + 1);
        };

        dom.addEventListener(FOCUS_GRIP, asked);

        return () => dom.removeEventListener(FOCUS_GRIP, asked);
    }, [editor]);

    const selectedCard =
        current !== null &&
        editor.state.selection instanceof NodeSelection &&
        current.node.isAtom;
    const shown =
        current !== null &&
        (forced ||
            menuOpen ||
            (focused && (byKey || isTouch() || selectedCard)));

    // Placed beside the current block, again after every change.
    useLayoutEffect(() => {
        if (
            !shown ||
            current === null ||
            box.current === null ||
            handle.current === null
        ) {
            return;
        }

        const block = editor.view.nodeDOM(current.pos);

        if (!(block instanceof Element)) {
            return;
        }

        const origin = box.current.getBoundingClientRect();

        setAt({
            x: gutterLeft(handle.current, block, box.current),
            y: block.getBoundingClientRect().top - origin.top,
        });
    }, [shown, current, editor]);

    // Each ask focuses the grip once, as soon as the handle is placed.
    const answered = useRef(0);

    useEffect(() => {
        if (focusAsked > answered.current && at !== null) {
            answered.current = focusAsked;
            grip.current?.focus();
        }
    }, [focusAsked, at]);

    const menu = useCallback(
        (open: boolean) => {
            setMenuOpen(open);
            // The pointer's handle stays put while its menu is open.
            editor.view.dispatch(
                editor.state.tr.setMeta('lockDragHandle', open),
            );
        },
        [editor],
    );

    const sameBlock =
        hovered !== null && current !== null && hovered.pos === current.pos;

    return (
        <div ref={box} className="pointer-events-none absolute inset-0">
            <DragHandle
                editor={editor}
                nested={false}
                className="teme-block-handle"
                computePositionConfig={POSITION}
                onNodeChange={({ node, pos }) =>
                    setHovered(
                        node === null || pos < 0 ? null : blockAt(editor, pos),
                    )
                }
                onElementDragStart={() => {
                    const element =
                        hovered === null
                            ? null
                            : editor.view.nodeDOM(hovered.pos);
                    dragged.current =
                        element instanceof HTMLElement ? element : null;
                    dragged.current?.classList.add('opacity-40');
                }}
                onElementDragEnd={() => {
                    dragged.current?.classList.remove('opacity-40');
                    dragged.current = null;
                }}
            >
                <HandleButtons
                    editor={editor}
                    block={hovered}
                    cards={cards}
                    source="pointer"
                    onAdd={onAdd}
                    onMenu={menu}
                />
            </DragHandle>
            {shown && !(sameBlock && !byKey && !forced) && (
                <div
                    ref={handle}
                    className="pointer-events-auto absolute top-(--handle-y) left-(--handle-x) z-(--z-sticky) data-[placed=false]:invisible"
                    data-placed={at !== null}
                    style={
                        {
                            '--handle-x': `${at?.x ?? 0}px`,
                            '--handle-y': `${at?.y ?? 0}px`,
                        } as CSSProperties
                    }
                    onFocus={() => setFocused(true)}
                    onBlur={(event) => {
                        if (
                            !event.currentTarget.contains(
                                event.relatedTarget as Node | null,
                            ) &&
                            !editor.view.hasFocus() &&
                            !menuOpen
                        ) {
                            setForced(false);
                            setFocused(false);
                        }
                    }}
                >
                    <HandleButtons
                        editor={editor}
                        block={current}
                        cards={cards}
                        source="selection"
                        gripRef={grip}
                        onAdd={onAdd}
                        onMenu={menu}
                    />
                </div>
            )}
        </div>
    );
}
