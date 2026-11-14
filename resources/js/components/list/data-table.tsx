// Later (I-200): plugins contribute columns and actions to these lists in phase 5.
import { ArrowDown, ArrowUp } from 'lucide-react';
import type { HTMLAttributes, ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type SortDirection = 'asc' | 'desc';

/**
 * One column of a list: who added it, its heading, and how it draws a row.
 * With `sort`, its heading is a button: `key` is the list's sort, and
 * `direction` the one in force, or null when the list is sorted by another.
 */
export type Column<Row> = {
    id: string;
    owner: string;
    header: ReactNode;
    /** The name Screen options gives a column whose header isn't text. */
    label?: string;
    cell: (row: Row) => ReactNode;
    className?: string;
    sort?: { key: string; direction: SortDirection | null };
};

const ariaSort = { asc: 'ascending', desc: 'descending' } as const;

/**
 * A sortable heading: the label, then an arrow for the direction in force.
 * Clicking the active column flips it; clicking another asks for that sort
 * with no direction, so the list applies the sort's own default.
 */
function SortButton({
    label,
    sort,
    onSort,
}: {
    label: ReactNode;
    sort: { key: string; direction: SortDirection | null };
    onSort: (key: string, direction: SortDirection | null) => void;
}) {
    const Arrow = sort.direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <button
            type="button"
            className="hover:text-ink focus-visible:ring-ring inline-flex items-center gap-1 rounded-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
            onClick={() =>
                onSort(
                    sort.key,
                    sort.direction === null
                        ? null
                        : sort.direction === 'asc'
                          ? 'desc'
                          : 'asc',
                )
            }
        >
            {label}
            {sort.direction !== null && (
                <Arrow aria-hidden className="size-3" />
            )}
        </button>
    );
}

/**
 * A list's rows, in the one bordered region a list screen has: 44px rows
 * separated by hairlines under a 36px muted header. With no rows it draws
 * `empty` inside the same frame.
 */
export function DataTable<Row>({
    columns,
    rows,
    rowKey,
    caption,
    empty,
    onSort,
    rowProps,
    describedBy,
}: {
    columns: Column<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    caption: string;
    empty?: ReactNode;
    /** Called with a column's sort key when its heading is clicked. */
    onSort?: (key: string, direction: SortDirection | null) => void;
    /** Attributes for each row, such as the Comments screen's roving tabindex and its keys (1.8c′'s D15); none by default. */
    rowProps?: (row: Row, index: number) => HTMLAttributes<HTMLTableRowElement>;
    /** The ID of what describes the table, such as a hint about its keys. */
    describedBy?: string;
}) {
    return (
        <Card className="overflow-x-auto">
            {rows.length === 0 && empty !== undefined ? (
                empty
            ) : (
                <table
                    className="w-full border-collapse"
                    aria-describedby={describedBy}
                >
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.id}
                                    scope="col"
                                    aria-sort={
                                        column.sort?.direction
                                            ? ariaSort[column.sort.direction]
                                            : undefined
                                    }
                                    className={cn(
                                        'bg-surface-2 text-ink-2 border-line h-9 border-b px-4 py-[9px] text-left text-[11px] leading-[18px] font-medium whitespace-nowrap *:align-middle',
                                        column.className,
                                    )}
                                >
                                    {column.sort && onSort ? (
                                        <SortButton
                                            label={column.header}
                                            sort={column.sort}
                                            onSort={onSort}
                                        />
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => {
                            const extra = rowProps?.(row, index);

                            return (
                                <tr
                                    key={rowKey(row)}
                                    {...extra}
                                    className={cn(
                                        'group/row h-11 [&:last-child>td]:border-b-0',
                                        extra?.className,
                                    )}
                                >
                                    {columns.map((column) => (
                                        <td
                                            key={column.id}
                                            className={cn(
                                                'border-line border-b px-4 py-3 align-top text-[13px] leading-5 *:align-top',
                                                column.className,
                                            )}
                                        >
                                            {column.cell(row)}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            )}
        </Card>
    );
}
