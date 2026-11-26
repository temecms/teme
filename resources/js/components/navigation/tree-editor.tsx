import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/adapter/element-adapter';
import { combine } from '@atlaskit/pragmatic-drag-and-drop/utils/combine';
import { attachClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/attach-closest-edge';
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/extract-closest-edge';
import type { Edge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/types';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ChevronRight,
    GripVertical,
    MoreHorizontal,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MenuItem } from '@/types';

/** The deepest a menu goes (SaveNavigationItems::MAX_DEPTH). */
export const MAX_DEPTH = 3;

/** A menu item as the editor holds it, with a key React can follow as it moves. */
export type EditorItem = Omit<MenuItem, 'children'> & {
    key: string;
    children: EditorItem[];
};

type Move = 'up' | 'down' | 'indent' | 'outdent';

let nextKey = 0;

/** The server's items, each given a key that stays with it as it moves. */
export function withKeys(items: MenuItem[]): EditorItem[] {
    return items.map((item) => ({
        ...item,
        key: `item-${nextKey++}`,
        children: withKeys(item.children),
    }));
}

/** A new item, as the Add item panel makes it. */
export function newItem(item: Omit<MenuItem, 'children'>): EditorItem {
    return { ...item, key: `item-${nextKey++}`, children: [] };
}

/** An item as SaveNavigationItems takes it. */
export type ItemPayload = {
    kind: string;
    target_id: string | null;
    url: string | null;
    label: string;
    opens_new_tab: boolean;
    children: ItemPayload[];
};

/** The tree as SaveNavigationItems takes it. */
export function toPayload(items: EditorItem[]): ItemPayload[] {
    return items.map((item) => ({
        kind: item.kind,
        target_id: item.targetId,
        url: item.url,
        label: item.label ?? '',
        opens_new_tab: item.opensNewTab,
        children: toPayload(item.children),
    }));
}

function listAt(root: EditorItem[], parentPath: number[]): EditorItem[] {
    let list = root;

    for (const index of parentPath) {
        list = list[index].children;
    }

    return list;
}

function height(item: EditorItem): number {
    return 1 + Math.max(0, ...item.children.map(height));
}

/** How deep the deepest heading sits in an item's branch, the item being 1; 0 with none. */
function headingDepth(item: EditorItem): number {
    const below = Math.max(0, ...item.children.map(headingDepth));

    if (below > 0) {
        return below + 1;
    }

    return item.kind === 'heading' ? 1 : 0;
}

/** What an item shows as: its own label, else its target's title. */
function nameOf(item: EditorItem): string {
    return item.label || item.target.replace(/^[^:]+:\s*/, '');
}

/**
 * Why a move can't be made, or null when it can: indenting goes under the
 * item above, and never past MAX_DEPTH levels with the item's own children.
 */
function refusal(
    root: EditorItem[],
    path: number[],
    move: Move,
): string | null {
    const index = path[path.length - 1];
    const list = listAt(root, path.slice(0, -1));

    switch (move) {
        case 'up':
            return index === 0 ? 'It is already first.' : null;
        case 'down':
            return index === list.length - 1 ? 'It is already last.' : null;
        case 'indent': {
            if (index === 0) {
                return 'There is no item above it to go under.';
            }

            if (path.length + height(list[index]) > MAX_DEPTH) {
                return `Menus go ${MAX_DEPTH} levels deep at most.`;
            }

            // A heading opens the items under it, and none can go under the last level (I-2628).
            const depth = headingDepth(list[index]);

            return depth > 0 && path.length + depth >= MAX_DEPTH
                ? 'A heading can’t go to the third level, where nothing can go under it.'
                : null;
        }
        case 'outdent':
            return path.length === 1 ? 'It is already at the top level.' : null;
    }
}

/** The tree after the move, and where the item now is. */
function moved(
    root: EditorItem[],
    path: number[],
    move: Move,
): { tree: EditorItem[]; path: number[] } {
    const tree = structuredClone(root);
    const parentPath = path.slice(0, -1);
    const index = path[path.length - 1];
    const list = listAt(tree, parentPath);
    const [item] = list.splice(index, 1);

    switch (move) {
        case 'up':
            list.splice(index - 1, 0, item);

            return { tree, path: [...parentPath, index - 1] };
        case 'down':
            list.splice(index + 1, 0, item);

            return { tree, path: [...parentPath, index + 1] };
        case 'indent': {
            const above = list[index - 1];
            above.children.push(item);

            return {
                tree,
                path: [...parentPath, index - 1, above.children.length - 1],
            };
        }
        case 'outdent': {
            const outerPath = parentPath.slice(0, -1);
            const parentIndex = parentPath[parentPath.length - 1];
            listAt(tree, outerPath).splice(parentIndex + 1, 0, item);

            return { tree, path: [...outerPath, parentIndex + 1] };
        }
    }
}

/** How a move is said aloud once it's made. */
function spoken(tree: EditorItem[], path: number[], move: Move): string {
    const item = listAt(tree, path.slice(0, -1))[path[path.length - 1]];
    const count = listAt(tree, path.slice(0, -1)).length;
    const place = `item ${path[path.length - 1] + 1} of ${count}`;

    if (move === 'indent') {
        const parent = listAt(tree, path.slice(0, -2))[path[path.length - 2]];

        return `Moved “${nameOf(item)}” under “${nameOf(parent)}”.`;
    }

    return move === 'outdent'
        ? `Moved “${nameOf(item)}” out a level: ${place}.`
        : `Moved “${nameOf(item)}” ${move}: ${place}.`;
}

const KEYS: Record<string, Move> = {
    ArrowUp: 'up',
    ArrowDown: 'down',
    ArrowRight: 'indent',
    ArrowLeft: 'outdent',
};

const MOVES: { move: Move; label: string; icon: typeof ArrowUp }[] = [
    { move: 'up', label: 'Move up', icon: ArrowUp },
    { move: 'down', label: 'Move down', icon: ArrowDown },
    { move: 'indent', label: 'Move under the item above', icon: ArrowRight },
    { move: 'outdent', label: 'Move out a level', icon: ArrowLeft },
];

/** What a dragged item carries: a marker, so nothing else dropped here is taken for one. */
type ItemDrag = { teme: 'menu-item'; key: string };

const isItemDrag = (data: Record<string | symbol, unknown>): data is ItemDrag =>
    data.teme === 'menu-item';

/** Where the item with this key sits in the tree, or null. */
function pathOf(list: EditorItem[], key: string): number[] | null {
    for (const [index, item] of list.entries()) {
        if (item.key === key) {
            return [index];
        }

        const below = pathOf(item.children, key);

        if (below !== null) {
            return [index, ...below];
        }
    }

    return null;
}

/**
 * The tree after a dragged item lands before or after another, beside it,
 * and how that is said aloud; or why it can't land there.
 */
function placed(
    root: EditorItem[],
    key: string,
    targetKey: string,
    after: boolean,
): { tree: EditorItem[]; spoken: string } | string {
    const from = pathOf(root, key);
    const to = pathOf(root, targetKey);

    if (from === null || to === null || key === targetKey) {
        return '';
    }

    if (from.every((index, level) => to[level] === index)) {
        return __('An item can’t go among the items under it.');
    }

    const item = listAt(root, from.slice(0, -1))[from[from.length - 1]];

    if (to.length - 1 + height(item) > MAX_DEPTH) {
        return __('Menus go :count levels deep at most.', {
            count: MAX_DEPTH,
        });
    }

    const depth = headingDepth(item);

    if (depth > 0 && to.length + depth - 1 >= MAX_DEPTH) {
        return 'A heading can’t go to the third level, where nothing can go under it.';
    }

    const tree = structuredClone(root);
    const source = listAt(tree, from.slice(0, -1));
    const [taken] = source.splice(from[from.length - 1], 1);
    const landing = pathOf(tree, targetKey) as number[];
    const list = listAt(tree, landing.slice(0, -1));
    list.splice(landing[landing.length - 1] + (after ? 1 : 0), 0, taken);

    return {
        tree,
        spoken: __('Moved “:name”.', { name: nameOf(taken) }),
    };
}

/** What an item is, as its chip says it: Page, Category, Home page, Address. */
function kindOf(item: EditorItem): string {
    switch (item.kind) {
        case 'home':
            return __('Home page');
        case 'heading':
            return __('Heading');
        case 'url':
            return __('Address');
        case 'languages':
            return __('Language switcher');
        default:
            return item.target.includes(':')
                ? item.target.slice(0, item.target.indexOf(':'))
                : item.target;
    }
}

/**
 * One item's row: dragged by its grip onto another item, landing before or
 * after it, with a line where it will land. The arrows do the same from
 * the keyboard, and go under or out a level as well.
 */
function DragRow({
    itemKey,
    lead,
    head,
    onKeyDown,
    children,
}: {
    itemKey: string;
    lead: ReactNode;
    /** The label and, on a phone, the "…" menu: kept on the row's first line. */
    head: ReactNode;
    onKeyDown: (event: KeyboardEvent) => void;
    children: ReactNode;
}) {
    const row = useRef<HTMLDivElement>(null);
    const grip = useRef<HTMLSpanElement>(null);
    const [edge, setEdge] = useState<Edge | null>(null);
    const [dragging, setDragging] = useState(false);

    useEffect(() => {
        const element = row.current;
        const handle = grip.current;

        if (element === null || handle === null) {
            return;
        }

        return combine(
            draggable({
                element,
                dragHandle: handle,
                getInitialData: (): ItemDrag => ({
                    teme: 'menu-item',
                    key: itemKey,
                }),
                onDragStart: () => setDragging(true),
                onDrop: () => setDragging(false),
            }),
            dropTargetForElements({
                element,
                canDrop: ({ source }) =>
                    isItemDrag(source.data) && source.data.key !== itemKey,
                getData: ({ input }) =>
                    attachClosestEdge(
                        { key: itemKey },
                        { element, input, allowedEdges: ['top', 'bottom'] },
                    ),
                onDrag: ({ self }) => setEdge(extractClosestEdge(self.data)),
                onDragLeave: () => setEdge(null),
                onDrop: () => setEdge(null),
            }),
        );
    }, [itemKey]);

    return (
        <div
            ref={row}
            className={cn(
                'bg-surface relative flex flex-wrap items-center gap-2 rounded-md px-2 py-1',
                dragging && 'opacity-50',
            )}
            onKeyDown={onKeyDown}
        >
            <div className="flex items-center gap-2 max-sm:min-w-0 max-sm:basis-full sm:flex-1">
                {lead}
                <span
                    ref={grip}
                    aria-hidden
                    title={__('Drag to move')}
                    className="text-ink-3 flex cursor-grab items-center"
                >
                    <GripVertical className="size-4" />
                </span>
                {head}
            </div>
            {children}
            {edge !== null && (
                <span
                    aria-hidden
                    className={cn(
                        'bg-accent pointer-events-none absolute inset-x-0 h-0.5',
                        edge === 'top' ? '-top-1' : '-bottom-1',
                    )}
                />
            )}
        </div>
    );
}

/**
 * A menu's items as an editable tree: each a compact row with its label,
 * a chip saying what it is, why it isn't shown if it isn't, and "Open in
 * new tab"; dragged by its grip, or moved with the arrows or Alt and the
 * arrow keys, never past MAX_DEPTH levels; an item's children fold away. Focus
 * stays on the moved item, and each move is announced. `errors` are the
 * last save's, keyed as SaveNavigationItems names them (`tree.0.children.1`).
 */
export function TreeEditor({
    items,
    onChange,
    errors,
}: {
    items: EditorItem[];
    onChange: (items: EditorItem[]) => void;
    errors: Record<string, string>;
}) {
    const [announcement, setAnnouncement] = useState('');
    const [focus, setFocus] = useState<{
        key: string;
        control: string;
        later?: boolean;
    } | null>(null);
    // Items whose children are folded away, by key.
    const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set());
    const toggle = (key: string) =>
        setCollapsed((was) => {
            const next = new Set(was);

            if (!next.delete(key)) {
                next.add(key);
            }

            return next;
        });
    // The latest tree and change, for a drop that lands after a render.
    const latest = useRef({ items, onChange });
    latest.current = { items, onChange };

    useEffect(
        () =>
            monitorForElements({
                canMonitor: ({ source }) => isItemDrag(source.data),
                onDrop: ({ source, location }) => {
                    const target = location.current.dropTargets[0];

                    if (target === undefined || !isItemDrag(source.data)) {
                        return;
                    }

                    const result = placed(
                        latest.current.items,
                        source.data.key,
                        String(target.data.key),
                        extractClosestEdge(target.data) === 'bottom',
                    );

                    if (typeof result === 'string') {
                        setAnnouncement(result);

                        return;
                    }

                    latest.current.onChange(result.tree);
                    setAnnouncement(result.spoken);
                },
            }),
        [],
    );
    // A move chosen from an item's menu brings focus back to that menu's button itself,
    // once the menu has closed and let focus go.
    const movedFromMenu = useRef<{ key: string; control: string } | null>(null);

    useEffect(() => {
        if (focus === null) {
            return;
        }

        const row = document.querySelector(`[data-item-key="${focus.key}"]`);
        const control =
            row?.querySelector<HTMLElement>(
                `[data-control="${focus.control}"]:not(:disabled)`,
            ) ?? row?.querySelector<HTMLElement>("input[data-control='label']");

        if (!focus.later) {
            control?.focus();

            return;
        }

        // A removed item's menu hands focus back as it goes: take it after that.
        const timer = window.setTimeout(() => control?.focus(), 0);

        return () => window.clearTimeout(timer);
    }, [focus, items]);

    /** Makes the move, or says why not; where focus goes after it, or null when refused. */
    const apply = (
        path: number[],
        move: Move,
        control: string,
    ): { key: string; control: string } | null => {
        const reason = refusal(items, path, move);

        if (reason !== null) {
            setAnnouncement(reason);

            return null;
        }

        const result = moved(items, path, move);
        const item = listAt(result.tree, result.path.slice(0, -1))[
            result.path[result.path.length - 1]
        ];

        onChange(result.tree);
        setAnnouncement(spoken(result.tree, result.path, move));
        setFocus({ key: item.key, control });

        return { key: item.key, control };
    };

    const update = (path: number[], change: Partial<EditorItem>) => {
        const tree = structuredClone(items);
        const list = listAt(tree, path.slice(0, -1));
        list[path[path.length - 1]] = {
            ...list[path[path.length - 1]],
            ...change,
        };
        onChange(tree);
    };

    /** Removes the item; from its menu, focus goes to the item now in its place, else the one before, else its parent. */
    const remove = (path: number[], fromMenu = false) => {
        const tree = structuredClone(items);
        const siblings = listAt(tree, path.slice(0, -1));
        const index = path[path.length - 1];
        const [item] = siblings.splice(index, 1);
        onChange(tree);

        if (fromMenu) {
            const next =
                siblings[index] ??
                siblings[index - 1] ??
                (path.length > 1
                    ? listAt(tree, path.slice(0, -2))[path[path.length - 2]]
                    : undefined);

            if (next !== undefined) {
                setFocus({ key: next.key, control: 'more', later: true });
            }
        }

        setAnnouncement(
            item.children.length > 0
                ? `Removed “${nameOf(item)}” and the items under it.`
                : `Removed “${nameOf(item)}”.`,
        );
    };

    const rows = (list: EditorItem[], parentPath: number[], key: string) => (
        <ul className="grid gap-1">
            {list.map((item, index) => {
                const path = [...parentPath, index];
                const errorKey = `${key}.${index}`;
                const error = errors[errorKey];
                const name = nameOf(item);
                const heading = item.kind === 'heading';
                const warning =
                    heading && item.children.length === 0
                        ? __('Needs an item under it')
                        : item.warning;
                const folded = collapsed.has(item.key);

                return (
                    <li key={item.key} data-item-key={item.key}>
                        <DragRow
                            itemKey={item.key}
                            lead={
                                item.children.length > 0 ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        data-control="fold"
                                        aria-expanded={!folded}
                                        aria-label={__(
                                            'Show or hide the items under :name',
                                            { name },
                                        )}
                                        onClick={() => toggle(item.key)}
                                    >
                                        <ChevronRight
                                            aria-hidden
                                            className={cn(
                                                'transition-transform motion-reduce:transition-none',
                                                !folded && 'rotate-90',
                                            )}
                                        />
                                    </Button>
                                ) : (
                                    <span aria-hidden className="size-8" />
                                )
                            }
                            head={
                                <>
                                    <Input
                                        data-control="label"
                                        aria-label={`Label for ${item.target}`}
                                        aria-describedby={
                                            error
                                                ? `${item.key}-error`
                                                : undefined
                                        }
                                        aria-invalid={error ? true : undefined}
                                        value={item.label ?? ''}
                                        aria-required={
                                            heading ? true : undefined
                                        }
                                        placeholder={
                                            item.kind === 'url' || heading
                                                ? 'Label'
                                                : nameOf({
                                                      ...item,
                                                      label: null,
                                                  })
                                        }
                                        maxLength={200}
                                        className="h-(--ctl-h) min-w-0 flex-1 sm:min-w-40"
                                        onChange={(event) =>
                                            update(path, {
                                                label: event.target.value,
                                            })
                                        }
                                    />
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                data-control="more"
                                                aria-label={__(
                                                    'More actions for :name',
                                                    { name },
                                                )}
                                                className="sm:hidden"
                                            >
                                                <MoreHorizontal aria-hidden />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            onCloseAutoFocus={(event) => {
                                                const moved =
                                                    movedFromMenu.current;

                                                if (moved !== null) {
                                                    movedFromMenu.current =
                                                        null;
                                                    event.preventDefault();
                                                    setFocus({ ...moved });
                                                }
                                            }}
                                        >
                                            {MOVES.map(
                                                ({
                                                    move,
                                                    label,
                                                    icon: Icon,
                                                }) => {
                                                    const reason = refusal(
                                                        items,
                                                        path,
                                                        move,
                                                    );

                                                    return (
                                                        <DropdownMenuItem
                                                            key={move}
                                                            disabled={
                                                                reason !== null
                                                            }
                                                            className="h-auto min-h-(--ctl-h) items-start py-2"
                                                            onSelect={() => {
                                                                movedFromMenu.current =
                                                                    apply(
                                                                        path,
                                                                        move,
                                                                        'more',
                                                                    );
                                                            }}
                                                        >
                                                            <Icon aria-hidden />
                                                            <span className="grid">
                                                                {label}
                                                                {reason !==
                                                                    null && (
                                                                    <span className="text-small text-ink-3">
                                                                        {reason}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </DropdownMenuItem>
                                                    );
                                                },
                                            )}
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    remove(path, true)
                                                }
                                            >
                                                <Trash2 aria-hidden />
                                                {__('Remove')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </>
                            }
                            onKeyDown={(event: KeyboardEvent) => {
                                const move = event.altKey
                                    ? KEYS[event.key]
                                    : undefined;

                                if (move !== undefined) {
                                    event.preventDefault();
                                    const control =
                                        (event.target as HTMLElement).dataset
                                            .control ?? 'label';
                                    apply(path, move, control);
                                }
                            }}
                        >
                            <StatusPill tone="neutral" flat>
                                {kindOf(item)}
                            </StatusPill>
                            {item.kind === 'url' && (
                                <span className="text-small text-ink-2 min-w-0 truncate">
                                    {item.target}
                                </span>
                            )}
                            {warning && (
                                <StatusPill tone="warn">{warning}</StatusPill>
                            )}
                            {!heading && (
                                <Collapsible className="basis-full sm:basis-auto">
                                    <CollapsibleTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="xs"
                                            data-control="details"
                                            className="group"
                                            aria-label={__(
                                                'Details for :name',
                                                { name },
                                            )}
                                        >
                                            {__('Details')}
                                            <ChevronRight
                                                aria-hidden
                                                className="size-3.5 transition-transform group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                                            />
                                        </Button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="pt-1">
                                        <label className="text-small flex items-center gap-2">
                                            <Checkbox
                                                data-control="new-tab"
                                                checked={item.opensNewTab}
                                                onCheckedChange={(checked) =>
                                                    update(path, {
                                                        opensNewTab:
                                                            checked === true,
                                                    })
                                                }
                                            />
                                            {__('Open in new tab')}
                                        </label>
                                    </CollapsibleContent>
                                </Collapsible>
                            )}
                            <div
                                role="group"
                                aria-label={__('Move or remove :name', {
                                    name,
                                })}
                                className="flex items-center max-sm:hidden"
                            >
                                {MOVES.map(({ move, label, icon: Icon }) => {
                                    const reason = refusal(items, path, move);

                                    return (
                                        <Button
                                            key={move}
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            data-control={move}
                                            aria-label={`${label}: ${name}`}
                                            title={reason ?? label}
                                            disabled={reason !== null}
                                            onClick={() =>
                                                apply(path, move, move)
                                            }
                                        >
                                            <Icon aria-hidden />
                                        </Button>
                                    );
                                })}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    data-control="remove"
                                    aria-label={`Remove ${name}`}
                                    title={__('Remove')}
                                    onClick={() => remove(path)}
                                >
                                    <X aria-hidden />
                                </Button>
                            </div>
                            {error && (
                                <p
                                    id={`${item.key}-error`}
                                    className="text-small text-danger-ink basis-full"
                                >
                                    {error}
                                </p>
                            )}
                        </DragRow>
                        {item.children.length > 0 && !folded && (
                            <div className="ms-6 mt-1">
                                {rows(
                                    item.children,
                                    path,
                                    `${errorKey}.children`,
                                )}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );

    return (
        <div className="grid gap-2">
            {rows(items, [], 'tree')}
            {/* Stays in the page, empty, so each move is announced reliably. */}
            <p role="status" className="sr-only">
                {announcement}
            </p>
        </div>
    );
}
