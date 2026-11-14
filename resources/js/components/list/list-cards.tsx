import { ArrowDownUp } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import type { CardLayout } from '@/types/list-pages';
import type { Column, SortDirection } from './data-table';

/** Whether a cell draws anything: an empty one leaves no fact, and no dot. */
const drawn = (node: ReactNode): boolean =>
    node !== null && node !== undefined && node !== false && node !== '';

/**
 * A list's rows as cards on a phone (I-3422): each card holds the tick box,
 * the title cell with its line of actions, one line of facts the list
 * declares, a body when it declares one, and the "…" menu at the top right.
 * Above them, Select all and Sort.
 */
export function ListCards<Row>({
    columns,
    rows,
    rowKey,
    caption,
    layout,
    onSort,
    selectedKeys,
}: {
    columns: Column<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    caption: string;
    layout: CardLayout;
    onSort?: (key: string, direction: SortDirection | null) => void;
    selectedKeys?: Set<string | number>;
}) {
    const byId = new Map(columns.map((column) => [column.id, column]));
    const select =
        layout.select !== undefined ? byId.get(layout.select) : undefined;
    const title = byId.get(layout.titleColumn);
    const menu = layout.menu !== undefined ? byId.get(layout.menu) : undefined;
    const body = layout.body !== undefined ? byId.get(layout.body) : undefined;
    const facts = layout.facts.flatMap((id) => {
        const column = byId.get(id);

        return column === undefined ? [] : [column];
    });
    const sort = layout.sort;

    return (
        <div className="grid gap-3 p-3">
            {(select !== undefined || (sort !== undefined && onSort)) && (
                <div
                    data-slot="cards-bar"
                    className="flex flex-wrap items-center justify-between gap-2"
                >
                    {select !== undefined && (
                        <label className="text-small text-ink-2 flex min-h-11 items-center gap-3 px-1">
                            {select.header}
                            <span>
                                {layout.selectLabel ?? __('Select all')}
                            </span>
                        </label>
                    )}
                    {sort !== undefined && onSort && (
                        <div className="flex items-center gap-1">
                            <SelectField
                                aria-label={__('Sort')}
                                value={sort.current}
                                onValueChange={(key) => onSort(key, null)}
                                options={sort.options.map((option) => ({
                                    value: option.key,
                                    label: option.label,
                                }))}
                                className="w-40"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={__('Reverse the order')}
                                onClick={() =>
                                    onSort(
                                        sort.current,
                                        sort.direction === 'asc'
                                            ? 'desc'
                                            : 'asc',
                                    )
                                }
                            >
                                <ArrowDownUp aria-hidden="true" />
                            </Button>
                        </div>
                    )}
                </div>
            )}
            <ul
                aria-label={caption}
                data-slot="list-cards"
                data-card-facts={layout.facts.join(' ')}
                className="grid gap-3"
            >
                {rows.map((row) => {
                    const key = rowKey(row);
                    const shown = facts
                        .map((column) => ({
                            id: column.id,
                            node: column.cell(row),
                        }))
                        .filter((fact) => drawn(fact.node));

                    return (
                        <li
                            key={key}
                            data-slot="list-card"
                            data-selected={selectedKeys?.has(key) || undefined}
                            className="group/card bg-surface shadow-raised data-[selected]:bg-accent-soft grid gap-2 rounded-lg p-4"
                        >
                            <div className="flex items-start gap-2">
                                {select !== undefined && (
                                    <div
                                        data-card="select"
                                        className="-ms-3 -mt-3 grid size-11 shrink-0 place-items-center"
                                    >
                                        {select.cell(row)}
                                    </div>
                                )}
                                <div
                                    data-card="title"
                                    className="min-w-0 flex-1"
                                >
                                    {title?.cell(row)}
                                </div>
                                {menu !== undefined && (
                                    <div
                                        data-card="menu"
                                        className="-me-2 -mt-2 shrink-0"
                                    >
                                        {menu.cell(row)}
                                    </div>
                                )}
                            </div>
                            {shown.length > 0 && (
                                <div
                                    data-card="facts"
                                    className="text-small text-ink-3 flex flex-wrap items-center gap-x-1"
                                >
                                    {shown.map((fact, at) => (
                                        <Fragment key={fact.id}>
                                            {at > 0 && (
                                                <span aria-hidden="true">
                                                    ·
                                                </span>
                                            )}
                                            <span data-fact={fact.id}>
                                                {fact.node}
                                            </span>
                                        </Fragment>
                                    ))}
                                </div>
                            )}
                            {body !== undefined && (
                                <div
                                    data-card="body"
                                    className="text-body text-ink-2"
                                >
                                    {body.cell(row)}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
