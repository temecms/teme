import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    MoreHorizontal,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

/**
 * A menu's items as an editable tree: each with its label, what it points
 * at, why it isn't shown if it isn't, and "Open in new tab"; moved with
 * buttons or Alt and the arrow keys, never past MAX_DEPTH levels. Focus
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
    const [focus, setFocus] = useState<{ key: string; control: string } | null>(
        null,
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

        control?.focus();
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

    const remove = (path: number[]) => {
        const tree = structuredClone(items);
        const [item] = listAt(tree, path.slice(0, -1)).splice(
            path[path.length - 1],
            1,
        );
        onChange(tree);
        setAnnouncement(
            item.children.length > 0
                ? `Removed “${nameOf(item)}” and the items under it.`
                : `Removed “${nameOf(item)}”.`,
        );
    };

    const rows = (list: EditorItem[], parentPath: number[], key: string) => (
        <ul className="grid gap-2">
            {list.map((item, index) => {
                const path = [...parentPath, index];
                const errorKey = `${key}.${index}`;
                const error = errors[errorKey];
                const name = nameOf(item);
                const heading = item.kind === 'heading';
                const warning =
                    heading && item.children.length === 0
                        ? 'Needs an item under it'
                        : item.warning;

                return (
                    <li key={item.key} data-item-key={item.key}>
                        <div
                            className="bg-surface grid gap-2 rounded-md border p-3"
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
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex min-w-0 flex-1 items-center gap-2 max-sm:basis-full sm:flex-none">
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
                                        className="h-8 w-64 max-w-full min-w-0 max-sm:h-11 max-sm:flex-1"
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
                                                onSelect={() => remove(path)}
                                            >
                                                <Trash2 aria-hidden />
                                                {__('Remove')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                                <span className="text-small text-ink-2 min-w-0 truncate">
                                    {item.target}
                                </span>
                                {warning && (
                                    <StatusPill tone="warn">
                                        {warning}
                                    </StatusPill>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-1">
                                {!heading && (
                                    <label className="text-small me-2 flex items-center gap-2">
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
                                        Open in new tab
                                    </label>
                                )}
                                <div className="flex items-center gap-1 max-sm:hidden">
                                    {MOVES.map(
                                        ({ move, label, icon: Icon }) => {
                                            const reason = refusal(
                                                items,
                                                path,
                                                move,
                                            );

                                            return (
                                                <Button
                                                    key={move}
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    data-control={move}
                                                    aria-label={`${label}: ${name}`}
                                                    title={reason ?? label}
                                                    disabled={reason !== null}
                                                    onClick={() =>
                                                        apply(path, move, move)
                                                    }
                                                >
                                                    <Icon className="size-4" />
                                                </Button>
                                            );
                                        },
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        data-control="remove"
                                        aria-label={`Remove ${name}`}
                                        title="Remove"
                                        onClick={() => remove(path)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </div>
                            {error && (
                                <p
                                    id={`${item.key}-error`}
                                    className="text-small text-danger-ink"
                                >
                                    {error}
                                </p>
                            )}
                        </div>
                        {item.children.length > 0 && (
                            <div className="ms-6 mt-2">
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
