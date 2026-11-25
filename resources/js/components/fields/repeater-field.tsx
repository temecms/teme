import { ChevronDown, ChevronRight, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';

type Item = Record<string, unknown>;

/**
 * A repeater (docs/plans/plugin-sockets-2.md, A2): a list of items of one
 * shape, each in its own box that collapses to its title, with Move up,
 * Move down and Remove as words (the single-pointer path, I-1720), a count
 * line, then Add. The caller draws each item's fields; the Field above
 * already names the section, so the legend stays visually hidden.
 */
export function RepeaterField({
    id,
    label,
    items,
    onChange,
    itemLabel,
    itemTitle,
    empty,
    minItems,
    maxItems,
    children,
}: {
    id: string;
    label: string;
    items: Item[];
    onChange: (items: Item[]) => void;
    /** What one item is called, as in "Add version". */
    itemLabel: string;
    /** The key whose value titles a collapsed item. */
    itemTitle: string | null;
    /** A new item's values. */
    empty: Item;
    minItems: number;
    maxItems: number;
    children: (
        item: Item,
        index: number,
        change: (item: Item) => void,
    ) => ReactNode;
}) {
    // Which items are open, by a key that follows an item as it moves.
    const [keys, setKeys] = useState(() => items.map((_, index) => index));
    const [next, setNext] = useState(items.length);
    const [closed, setClosed] = useState<Set<number>>(new Set());

    const titleOf = (item: Item, index: number): string => {
        const title = itemTitle === null ? '' : item[itemTitle];

        return typeof title === 'string' && title.trim() !== ''
            ? title
            : `${itemLabel} ${index + 1}`;
    };

    // Whole translated phrases per case — never an English a/an vowel check.
    const named = itemLabel.trim() !== '' && itemLabel !== __('Item');
    const addLabel = named
        ? __('Add :item', { item: itemLabel.trim().toLowerCase() })
        : __('Add item');

    const move = (from: number, to: number) => {
        const moved = [...items];
        const movedKeys = [...keys];
        [moved[from], moved[to]] = [moved[to], moved[from]];
        [movedKeys[from], movedKeys[to]] = [movedKeys[to], movedKeys[from]];
        setKeys(movedKeys);
        onChange(moved);
    };

    return (
        <fieldset
            id={id}
            className="grid gap-3"
            data-test="repeater"
            data-count={items.length}
        >
            <legend className="sr-only">{label}</legend>
            <p className="text-caption text-ink-3" data-test="repeater-count">
                {__(':count of at most :max.', {
                    count: items.length,
                    max: maxItems,
                })}
            </p>
            <ol className="grid gap-3">
                {items.map((item, index) => {
                    const key = keys[index] ?? index;
                    const open = !closed.has(key);
                    const title = titleOf(item, index);

                    return (
                        <li
                            key={key}
                            data-test="repeater-item"
                            data-open={open || undefined}
                            className="border-line rounded-md border"
                        >
                            <div className="flex flex-wrap items-center gap-1 py-1 pr-2 pl-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="min-w-0 flex-1 justify-start"
                                    aria-expanded={open}
                                    onClick={() =>
                                        setClosed((current) => {
                                            const changed = new Set(current);

                                            if (open) {
                                                changed.add(key);
                                            } else {
                                                changed.delete(key);
                                            }

                                            return changed;
                                        })
                                    }
                                >
                                    {open ? (
                                        <ChevronDown aria-hidden="true" />
                                    ) : (
                                        <ChevronRight aria-hidden="true" />
                                    )}
                                    <span
                                        className="truncate"
                                        data-test="repeater-title"
                                    >
                                        {title}
                                    </span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={index === 0}
                                    aria-label={__('Move :item up', {
                                        item: title,
                                    })}
                                    onClick={() => move(index, index - 1)}
                                >
                                    {__('Move up')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={index === items.length - 1}
                                    aria-label={__('Move :item down', {
                                        item: title,
                                    })}
                                    onClick={() => move(index, index + 1)}
                                >
                                    {__('Move down')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="danger-quiet"
                                    size="sm"
                                    disabled={items.length <= minItems}
                                    aria-label={__('Remove :item', {
                                        item: title,
                                    })}
                                    onClick={() => {
                                        setKeys(
                                            keys.filter(
                                                (_, at) => at !== index,
                                            ),
                                        );
                                        onChange(
                                            items.filter(
                                                (_, at) => at !== index,
                                            ),
                                        );
                                    }}
                                >
                                    {__('Remove')}
                                </Button>
                            </div>
                            {open && (
                                <div className="grid gap-5 px-4 pt-2 pb-4">
                                    {children(item, index, (changed) =>
                                        onChange(
                                            items.map((one, at) =>
                                                at === index ? changed : one,
                                            ),
                                        ),
                                    )}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ol>
            <div>
                <Button
                    type="button"
                    variant="neutral"
                    disabled={items.length >= maxItems}
                    data-test="repeater-add"
                    onClick={() => {
                        setKeys([...keys, next]);
                        setNext(next + 1);
                        onChange([...items, { ...empty }]);
                    }}
                >
                    <Plus aria-hidden="true" />
                    {addLabel}
                </Button>
            </div>
        </fieldset>
    );
}
