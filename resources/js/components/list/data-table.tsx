// Later (I-200): row actions, bulk actions and filters from plugins wait.
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, HTMLAttributes, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import type { CardLayout } from '@/types/list-pages';
import { ListCards } from './list-cards';
import { useCards } from './use-cards';

export type SortDirection = 'asc' | 'desc';

/** The table frame's ID, so the column chooser can measure the width it has. */
export const TABLE_FRAME_ID = 'list-table-frame';

/**
 * One column of a list: who added it, its heading, and how it draws a row.
 * With `sort`, its heading is a button: `key` is the list's sort, and
 * `direction` the one in force, or null when the list is sorted by another.
 */
export type Column<Row> = {
    id: string;
    owner: string;
    header: ReactNode;
    /** The name the column chooser gives a column whose header isn't text. */
    label?: string;
    cell: (row: Row) => ReactNode;
    className?: string;
    sort?: { key: string; direction: SortDirection | null };
    /** Its width in pixels, as docs/architecture/design-system.md section 4.26 declares it. */
    width?: number;
    /** The narrowest it gets, in pixels, such as the title's 320. */
    minWidth?: number;
};

const ariaSort = { asc: 'ascending', desc: 'descending' } as const;

/**
 * A sortable heading: the label, then an arrow for the direction in force,
 * or both ways at rest. Clicking the active column flips it; clicking
 * another asks for that sort with no direction, so the list applies the
 * sort's own default.
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
    const Arrow =
        sort.direction === null
            ? ArrowUpDown
            : sort.direction === 'asc'
              ? ArrowUp
              : ArrowDown;

    return (
        <Button
            type="button"
            variant="ghost"
            size="xs"
            className="text-small text-ink-3 hover:text-ink -ms-2 gap-1 font-medium"
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
            <Arrow aria-hidden="true" className="size-3.5" />
        </Button>
    );
}

/** A column's declared width and minimum, as the CSS its cells read. */
function sizes(column: {
    width?: number;
    minWidth?: number;
}): CSSProperties | undefined {
    if (column.width === undefined && column.minWidth === undefined) {
        return undefined;
    }

    return {
        ...(column.width !== undefined
            ? { '--col-w': `${column.width}px` }
            : {}),
        ...(column.minWidth !== undefined
            ? { '--col-min': `${column.minWidth}px` }
            : {}),
    } as CSSProperties;
}

/**
 * A list's rows (docs/architecture/design-system.md, section 4.26): a raised
 * frame the table scrolls sideways in, a 44px header that stays in view, rows
 * as tall as their title needs and no lines between them. Pinned columns stay
 * at the start and end while the rest scroll, each edge's shadow shown only
 * while something is underneath. Under 640px, a list that declares `cards`
 * draws each row as a card (I-3422). With no rows it draws `empty` inside the
 * same frame.
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
    selectedKeys,
    pinned,
    cards,
    footer,
    wideNote,
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
    /** The rows ticked, drawn on the accent's soft ground. */
    selectedKeys?: Set<string | number>;
    /** Column ids kept in view at the start and at the end while the rest scroll. */
    pinned?: { start: string[]; end: string[] };
    /** How the rows draw as cards on a phone; without it, the table everywhere. */
    cards?: CardLayout;
    /** Drawn inside the frame under the rows, such as the pager. */
    footer?: ReactNode;
    /** Said under the rows while the table is wider than its frame. */
    wideNote?: string;
}) {
    const asCards = useCards() && cards !== undefined;
    const scroller = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({
        start: false,
        end: false,
        wide: false,
    });

    useEffect(() => {
        const element = scroller.current;

        if (element === null) {
            return;
        }

        const measure = () => {
            const room = element.scrollWidth - element.clientWidth;

            setEdges({
                start: element.scrollLeft > 0,
                end: room - element.scrollLeft > 1,
                wide: room > 1,
            });
        };

        measure();
        element.addEventListener('scroll', measure, { passive: true });

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        if (element.firstElementChild !== null) {
            observer.observe(element.firstElementChild);
        }

        return () => {
            element.removeEventListener('scroll', measure);
            observer.disconnect();
        };
    }, [asCards, rows.length, columns.length]);

    // The header follows the page: the frame scrolls sideways, so `position: sticky` sticks to
    // the frame, not to the page. The cells are moved down by how far the frame's top has left the view.
    useEffect(() => {
        const element = scroller.current;

        if (element === null) {
            return;
        }

        let frame = 0;
        const follow = () => {
            frame = 0;
            const head = element.querySelector('thead');
            const room = element.clientHeight - (head?.offsetHeight ?? 0);
            const shift = Math.max(
                0,
                Math.min(-element.getBoundingClientRect().top, room),
            );

            element.style.setProperty('--head-shift', `${shift}px`);
        };
        const queue = () => {
            frame ||= requestAnimationFrame(follow);
        };

        follow();
        window.addEventListener('scroll', queue, { passive: true });
        window.addEventListener('resize', queue);

        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('scroll', queue);
            window.removeEventListener('resize', queue);
        };
    }, [asCards, rows.length]);

    const start = pinned?.start ?? [];
    const end = pinned?.end ?? [];
    // Each start-pinned column sits after the ones before it; only the last may grow.
    const offsets = new Map<string, number>();
    let left = 0;

    for (const id of start) {
        offsets.set(id, left);
        left += columns.find((column) => column.id === id)?.width ?? 0;
    }

    const lastStart = start[start.length - 1];
    const placed = (column: Column<Row>) => {
        if (offsets.has(column.id)) {
            return {
                style: { left: offsets.get(column.id) },
                className: cn(
                    'sticky z-(--z-sticky)',
                    column.id === lastStart && edges.start && 'shadow-raised',
                ),
            };
        }

        if (end.includes(column.id)) {
            return {
                style: { right: 0 },
                className: cn(
                    'sticky z-(--z-sticky)',
                    edges.end && 'shadow-raised',
                ),
            };
        }

        return { style: undefined, className: '' };
    };
    const widths = (column: Column<Row>) =>
        cn(
            column.width !== undefined && 'w-(--col-w) min-w-(--col-w)',
            column.minWidth !== undefined && 'min-w-(--col-min)',
        );

    if (rows.length === 0 && empty !== undefined) {
        return (
            <section
                data-slot="table-frame"
                className="bg-surface shadow-raised overflow-hidden rounded-lg"
            >
                {empty}
            </section>
        );
    }

    return (
        <section
            id={TABLE_FRAME_ID}
            data-slot="table-frame"
            className="bg-surface shadow-raised overflow-hidden rounded-lg"
        >
            {asCards ? (
                <ListCards
                    columns={columns}
                    rows={rows}
                    rowKey={rowKey}
                    caption={caption}
                    layout={cards}
                    onSort={onSort}
                    selectedKeys={selectedKeys}
                />
            ) : (
                <ScrollArea axis="x" ref={scroller}>
                    <table
                        className="w-full border-separate border-spacing-0"
                        aria-describedby={describedBy}
                    >
                        <caption className="sr-only">{caption}</caption>
                        <thead>
                            <tr>
                                {columns.map((column) => {
                                    const place = placed(column);

                                    return (
                                        <th
                                            key={column.id}
                                            scope="col"
                                            data-column={column.id}
                                            aria-sort={
                                                column.sort?.direction
                                                    ? ariaSort[
                                                          column.sort.direction
                                                      ]
                                                    : undefined
                                            }
                                            style={{
                                                ...sizes(column),
                                                ...place.style,
                                                transform:
                                                    'translateY(var(--head-shift, 0px))',
                                            }}
                                            className={cn(
                                                'bg-surface text-small text-ink-3 top-0 z-(--z-sticky) h-11 px-4 text-start align-middle font-medium whitespace-nowrap',
                                                place.className === ''
                                                    ? 'sticky'
                                                    : place.className,
                                                widths(column),
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
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => {
                                const extra = rowProps?.(row, index);
                                const key = rowKey(row);

                                return (
                                    <tr
                                        key={key}
                                        {...extra}
                                        data-selected={
                                            selectedKeys?.has(key) || undefined
                                        }
                                        className={cn(
                                            'group/row',
                                            extra?.className,
                                        )}
                                    >
                                        {columns.map((column) => {
                                            const place = placed(column);

                                            return (
                                                <td
                                                    key={column.id}
                                                    data-column={column.id}
                                                    style={{
                                                        ...sizes(column),
                                                        ...place.style,
                                                    }}
                                                    className={cn(
                                                        'bg-surface text-body text-ink-2 group-hover/row:bg-surface-2 hover:bg-surface-2 group-data-[selected]/row:bg-accent-soft px-4 py-3 align-top whitespace-nowrap',
                                                        place.className,
                                                        widths(column),
                                                        column.className,
                                                    )}
                                                >
                                                    {column.cell(row)}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </ScrollArea>
            )}
            {!asCards && wideNote !== undefined && edges.wide && (
                <p className="text-small text-ink-3 px-5 pt-3">{wideNote}</p>
            )}
            {footer}
        </section>
    );
}
