import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { BulkBar } from '@/components/list/bulk-bar';
import { ConfirmItems } from '@/components/list/confirm-items';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { numbersFor } from '@/components/list/numbered-pager';
import { PluginCell } from '@/components/list/plugin-cell';
import type { RowAction } from '@/components/list/row-actions';
import { RowLine } from '@/components/list/row-line';
import { RowMenu } from '@/components/list/row-menu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import type {
    AreaPluginRef,
    AreaRowsData,
    AreaTableAsk,
    AreaTableBlock,
} from '@/types/plugin-area';

type Row = AreaRowsData['rows'][number];

/** The words of the built-in actions. */
function actionLabel(action: string): string {
    switch (action) {
        case 'edit':
            return __('Edit');
        case 'duplicate':
            return __('Duplicate');
        case 'enable':
            return __('Enable');
        case 'disable':
            return __('Disable');
        default:
            return __('Delete');
    }
}

/** Line actions stay under the title; the rest wait in the row's menu. */
function onLine(action: string): boolean {
    return action === 'edit' || action === 'duplicate';
}

/**
 * A table of a plugin's records (docs/plans/plugin-sockets-2.md, A9): its
 * search, filters, sortable columns and pages as core's lists draw them, a
 * tick box a row when the table has bulk actions, Edit and Duplicate under
 * the name, the rest in the row's menu, and Add only in the empty state (the
 * page header holds the primary Add). A delete asks first. Every cell is
 * plain text.
 */
export function AreaTable({
    plugin,
    page,
    block,
    data,
    ask,
    onAsk,
}: {
    plugin: AreaPluginRef;
    page: string;
    block: AreaTableBlock;
    data: AreaRowsData;
    ask: AreaTableAsk;
    onAsk: (next: AreaTableAsk) => void;
}) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [deleting, setDeleting] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState(ask.search ?? '');
    const record = block.record;
    const titleColumn = block.columns[0]?.id ?? '';
    const titleOf = (row: Row): string => {
        const cell = row.cells[titleColumn];

        return cell === undefined ? row.id : String(cell.value);
    };
    const editHref = (row: Row) =>
        PluginAreaController.edit.url({
            code: plugin.code,
            record: record.id,
            id: row.id,
        });

    const act = (action: string, ids: string[], confirmed = false) =>
        router.post(
            PluginAreaController.act.url({
                code: plugin.code,
                record: record.id,
            }),
            { action, ids, confirmed },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setSelected(new Set());
                    setDeleting(null);
                },
            },
        );

    const asAction = (row: Row, action: string): RowAction => {
        if (action === 'edit') {
            return {
                id: action,
                owner: 'core',
                label: actionLabel(action),
                href: editHref(row),
            };
        }

        return {
            id: action,
            owner: 'core',
            label: actionLabel(action),
            destructive: action === 'delete',
            onSelect: () =>
                action === 'delete'
                    ? setDeleting([row.id])
                    : act(action, [row.id]),
        };
    };

    const lineOf = (row: Row): RowAction[] =>
        block.rowActions
            .filter(
                (action) => onLine(action) && !row.unavailable.includes(action),
            )
            .map((action) => asAction(row, action));

    const menuOf = (row: Row): RowAction[] =>
        block.rowActions
            .filter(
                (action) =>
                    !onLine(action) && !row.unavailable.includes(action),
            )
            .map((action) => asAction(row, action));

    const toggle = (id: string, on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const columns: Column<Row>[] = [
        ...(block.bulkActions.length > 0
            ? [
                  {
                      id: 'select',
                      owner: 'core',
                      header: (
                          <Checkbox
                              aria-label={__('Select every :plural shown', {
                                  plural: record.plural.toLowerCase(),
                              })}
                              checked={
                                  data.rows.length > 0 &&
                                  data.rows.every((row) => selected.has(row.id))
                              }
                              onCheckedChange={(checked) =>
                                  setSelected(
                                      checked === true
                                          ? new Set(
                                                data.rows.map((row) => row.id),
                                            )
                                          : new Set(),
                                  )
                              }
                          />
                      ),
                      label: __('Select'),
                      width: 48,
                      cell: (row: Row) => (
                          <Checkbox
                              aria-label={__('Select :title', {
                                  title: titleOf(row),
                              })}
                              checked={selected.has(row.id)}
                              onCheckedChange={(checked) =>
                                  toggle(row.id, checked === true)
                              }
                          />
                      ),
                  } satisfies Column<Row>,
              ]
            : []),
        ...block.columns.map((column, index): Column<Row> => ({
            id: column.id,
            owner: plugin.name,
            header: column.label,
            label: column.label,
            sort: column.sortable
                ? {
                      key: column.id,
                      direction:
                          ask.sort === column.id
                              ? (ask.direction ?? 'desc')
                              : null,
                  }
                : undefined,
            cell: (row: Row) => {
                const cell = <PluginCell value={row.cells[column.id]} />;
                const line = lineOf(row);

                if (index !== 0) {
                    return cell;
                }

                return (
                    <div className="grid gap-1">
                        {block.rowActions.includes('edit') ? (
                            <Link
                                href={editHref(row)}
                                className="text-ink text-body font-semibold underline-offset-2 hover:underline"
                            >
                                {cell}
                            </Link>
                        ) : (
                            <span className="text-ink text-body font-semibold">
                                {cell}
                            </span>
                        )}
                        {line.length > 0 && (
                            <RowLine title={titleOf(row)} items={line} />
                        )}
                    </div>
                );
            },
        })),
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            label: __('Actions'),
            width: 64,
            cell: (row: Row) =>
                menuOf(row).length > 0 ? (
                    <RowMenu items={menuOf(row)} title={titleOf(row)} />
                ) : null,
        },
    ];

    const pageNumber = Math.max(1, Number(ask.page ?? '1') || 1);
    const pages =
        data.total === null
            ? null
            : Math.max(1, Math.ceil(data.total / block.perPage));
    const hasNext =
        pages === null ? data.rows.length >= block.perPage : pageNumber < pages;
    const filtered =
        (ask.search ?? '') !== '' || Object.keys(ask.filters ?? {}).length > 0;
    const from =
        data.rows.length === 0 ? 0 : (pageNumber - 1) * block.perPage + 1;
    const to = from === 0 ? 0 : from + data.rows.length - 1;

    return (
        <section
            aria-label={record.plural}
            data-test="area-table"
            data-block={block.id}
            data-page={page}
            className="grid gap-3"
        >
            <div className="flex flex-wrap items-center gap-2">
                {block.search && (
                    <form
                        role="search"
                        className="min-w-48 flex-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            onAsk({ ...ask, search, page: undefined });
                        }}
                    >
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            aria-label={__('Search :plural', {
                                plural: record.plural.toLowerCase(),
                            })}
                            placeholder={__('Search :plural', {
                                plural: record.plural.toLowerCase(),
                            })}
                            data-test="area-table-search"
                        />
                    </form>
                )}
                {block.filters.map((filter) => (
                    <SelectField
                        key={filter.id}
                        aria-label={filter.label}
                        className="w-44"
                        emptyLabel={__('All :label', {
                            label: filter.label.toLowerCase(),
                        })}
                        options={filter.options}
                        value={ask.filters?.[filter.id] ?? ''}
                        onValueChange={(value) => {
                            const filters = { ...ask.filters };

                            if (value === '') {
                                delete filters[filter.id];
                            } else {
                                filters[filter.id] = value;
                            }

                            onAsk({ ...ask, filters, page: undefined });
                        }}
                    />
                ))}
            </div>

            {selected.size > 0 && block.bulkActions.length > 0 && (
                <BulkBar
                    count={selected.size}
                    onClear={() => setSelected(new Set())}
                >
                    {block.bulkActions.map((action) => (
                        <Button
                            key={action}
                            type="button"
                            size="sm"
                            variant={action === 'delete' ? 'danger' : 'neutral'}
                            className={
                                action === 'delete' ? undefined : 'bg-surface'
                            }
                            disabled={busy}
                            data-test={`area-bulk-${action}`}
                            onClick={() =>
                                action === 'delete'
                                    ? setDeleting([...selected])
                                    : act(action, [...selected])
                            }
                        >
                            {actionLabel(action)}
                        </Button>
                    ))}
                </BulkBar>
            )}

            <DataTable
                columns={columns}
                rows={data.rows}
                rowKey={(row) => row.id}
                caption={record.plural}
                selectedKeys={selected}
                onSort={(key, direction) =>
                    onAsk({
                        ...ask,
                        sort: key,
                        direction: direction ?? 'desc',
                        page: undefined,
                    })
                }
                empty={
                    filtered ? (
                        <EmptyState
                            kind="nothing-matches"
                            title={__('No :plural match.', {
                                plural: record.plural.toLowerCase(),
                            })}
                            action={
                                <Button
                                    type="button"
                                    variant="neutral"
                                    onClick={() => {
                                        setSearch('');
                                        onAsk({});
                                    }}
                                >
                                    {__('Clear the search and filters')}
                                </Button>
                            }
                        />
                    ) : (
                        <EmptyState
                            kind="nothing-yet"
                            title={__('No :plural yet.', {
                                plural: record.plural.toLowerCase(),
                            })}
                        />
                    )
                }
                footer={
                    data.rows.length > 0 &&
                    (pageNumber > 1 || hasNext || data.total !== null) ? (
                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-4">
                            <p className="text-small text-ink-3 tabular-nums">
                                {data.total === null
                                    ? __('Page :page', { page: pageNumber })
                                    : __(':from to :to of :total', {
                                          from,
                                          to,
                                          total: data.total,
                                      })}
                            </p>
                            {(pageNumber > 1 || hasNext) && (
                                <nav
                                    aria-label={__('Pages')}
                                    className="flex items-center gap-1"
                                >
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={pageNumber <= 1}
                                        aria-label={__('Previous page')}
                                        onClick={() =>
                                            onAsk({
                                                ...ask,
                                                page: String(pageNumber - 1),
                                            })
                                        }
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                    </Button>
                                    {pages !== null &&
                                        numbersFor(pageNumber, pages).map(
                                            (number, at) =>
                                                number === null ? (
                                                    <span
                                                        key={`gap-${at}`}
                                                        className="text-ink-3 px-1 tabular-nums"
                                                        aria-hidden="true"
                                                    >
                                                        …
                                                    </span>
                                                ) : (
                                                    <Button
                                                        key={number}
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="aria-[current=page]:bg-surface-3 aria-[current=page]:text-ink min-w-8 tabular-nums"
                                                        aria-current={
                                                            number ===
                                                            pageNumber
                                                                ? 'page'
                                                                : undefined
                                                        }
                                                        onClick={() =>
                                                            onAsk({
                                                                ...ask,
                                                                page: String(
                                                                    number,
                                                                ),
                                                            })
                                                        }
                                                    >
                                                        {number}
                                                    </Button>
                                                ),
                                        )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={!hasNext}
                                        aria-label={__('Next page')}
                                        onClick={() =>
                                            onAsk({
                                                ...ask,
                                                page: String(pageNumber + 1),
                                            })
                                        }
                                    >
                                        <ChevronRight aria-hidden="true" />
                                    </Button>
                                </nav>
                            )}
                        </div>
                    ) : undefined
                }
            />

            <ConfirmItems
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={
                    (deleting?.length ?? 0) === 1
                        ? __('Delete :title?', {
                              title: titleOf(
                                  data.rows.find(
                                      (row) => row.id === deleting?.[0],
                                  ) ?? {
                                      id: deleting?.[0] ?? '',
                                      cells: {},
                                      unavailable: [],
                                  },
                              ),
                          })
                        : __('Delete :count :plural?', {
                              count: deleting?.length ?? 0,
                              plural: record.plural.toLowerCase(),
                          })
                }
                description={__('This can’t be undone.')}
                items={(deleting ?? []).map((id) =>
                    titleOf(
                        data.rows.find((row) => row.id === id) ?? {
                            id,
                            cells: {},
                            unavailable: [],
                        },
                    ),
                )}
                listLabel={__(':plural to delete', { plural: record.plural })}
                confirmLabel={__('Delete')}
                processing={busy}
                onConfirm={() =>
                    deleting !== null && act('delete', deleting, true)
                }
            />
        </section>
    );
}

/** The primary Add for a table block, for the area page header. */
export function areaTableAdd(plugin: AreaPluginRef, block: AreaTableBlock) {
    return {
        href: PluginAreaController.create.url({
            code: plugin.code,
            record: block.record.id,
        }),
        label: __('Add :item', {
            item: block.record.label.toLowerCase(),
        }),
    };
}
