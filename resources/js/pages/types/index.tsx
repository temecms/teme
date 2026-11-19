import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Download, Plus, TriangleAlert, XCircle } from 'lucide-react';
import TypesController from '@/actions/App/Http/Admin/TypesController';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import type { RowAction } from '@/components/list/row-actions';
import { RowLine } from '@/components/list/row-line';
import { RowMenu } from '@/components/list/row-menu';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Notice } from '@/components/ui/notice';
import { PluginMark } from '@/components/ui/plugin-mark';
import { StatusPill } from '@/components/ui/status-pill';
import { useSectionCrumb } from '@/lib/crumbs';
import { __, __n } from '@/lib/i18n';
import { navIcon } from '@/lib/nav-icons';
import { index as typeFields } from '@/routes/types/fields';
import { index as typesIndex, show } from '@/routes/types';
import type { TypeRow, TypesIndexPage } from '@/types';

/**
 * Site › Types: every type the site keeps, code's and the ones made here,
 * with a refused type's reason and the way to open, change or delete it.
 */
export default function TypesIndex({ locked, types }: TypesIndexPage) {
    const title = __('Types');
    const siteCrumb = useSectionCrumb('site');

    setLayoutProps({
        breadcrumbs: [siteCrumb, { title, href: typesIndex() }],
    });

    const columns: Column<TypeRow>[] = [
        {
            id: 'type',
            owner: 'core',
            header: __('Type'),
            cell: (row) => <TypeCell row={row} locked={locked} />,
        },
        {
            id: 'admin',
            owner: 'core',
            header: __('Admin address'),
            className: 'hidden lg:table-cell',
            cell: (row) => (
                <code className="text-caption text-ink-2 font-mono">{`/admin/${row.segment}`}</code>
            ),
        },
        {
            id: 'public',
            owner: 'core',
            header: __('Public address'),
            className: 'hidden sm:table-cell',
            cell: (row) =>
                row.public && row.pattern !== null ? (
                    <code className="text-caption text-ink-2 font-mono break-all">
                        {row.pattern}
                    </code>
                ) : (
                    <span className="text-ink-3">{__('None: not public')}</span>
                ),
        },
        {
            id: 'items',
            owner: 'core',
            header: __('Items'),
            cell: (row) => <ItemsCell row={row} />,
        },
        {
            id: 'source',
            owner: 'core',
            header: __('Source'),
            className: 'hidden sm:table-cell',
            cell: (row) => <SourceBadge source={row.source} />,
        },
        {
            id: 'menu',
            owner: 'core',
            width: 56,
            header: <span className="sr-only">{__('Actions')}</span>,
            cell: (row) =>
                deletable(row, locked) ? (
                    <RowMenu title={row.plural} items={menuActions(row)} />
                ) : null,
        },
    ];

    return (
        <ListPage
            title={title}
            description={__(
                'The kinds of content this site keeps. Types declared by code are shown, but can’t be changed here.',
            )}
            actions={
                <>
                    <Button variant="neutral" asChild>
                        <a
                            href={TypesController.download.url()}
                            download
                            data-test="types-download"
                        >
                            <Download aria-hidden="true" />
                            {__('Download teme-types.json')}
                        </a>
                    </Button>
                    {!locked && (
                        <Button asChild variant="primary">
                            <Link
                                href={TypesController.create.url()}
                                data-test="types-add"
                            >
                                <Plus aria-hidden="true" />
                                {__('Add new type')}
                            </Link>
                        </Button>
                    )}
                </>
            }
        >
            <Head title={title} />

            {locked && (
                <Notice tone="info">
                    {__(
                        'Types are locked on this site (TEME_TYPES_LOCKED): change them where they’re made, and bring them here with :command. You can still look at each type, and download teme-types.json.',
                        { command: 'php artisan teme:types:import' },
                    )}
                </Notice>
            )}

            <DataTable
                columns={columns}
                rows={types}
                rowKey={(row) => row.key}
                caption={title}
                rowProps={(row) =>
                    row.source === 'refused'
                        ? { className: 'bg-danger-soft' }
                        : {}
                }
            />
        </ListPage>
    );
}

function TypeCell({ row, locked }: { row: TypeRow; locked: boolean }) {
    return (
        <div className="flex min-w-0 items-start gap-3">
            <IconTile icon={navIcon(row.icon)} />
            <div className="grid min-w-0 gap-0.5">
                <Link
                    href={show.url(row.key)}
                    className="text-ink focus-visible:outline-ring justify-self-start rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                >
                    {row.plural}
                </Link>
                <div className="flex flex-wrap items-center gap-2">
                    <code className="text-caption text-ink-2 font-mono break-all">
                        {row.key}
                    </code>
                    <span className="sm:hidden">
                        <SourceBadge source={row.source} />
                    </span>
                </div>
                {row.plugin !== null && (
                    <p className="text-ink-2 text-caption mt-1 flex flex-wrap items-center gap-1">
                        <PluginMark name={row.plugin} />
                        <span>
                            {row.pluginEnabled
                                ? __('Comes from :plugin', {
                                      plugin: row.plugin,
                                  })
                                : __(
                                      'Comes from :plugin, which isn’t enabled: hidden, with its items kept',
                                      { plugin: row.plugin },
                                  )}
                        </span>
                    </p>
                )}
                {row.nameClash !== null && (
                    <p className="text-warn-ink text-caption mt-1 flex items-start gap-1 text-pretty">
                        <span className="flex h-lh shrink-0 items-center">
                            <TriangleAlert aria-hidden className="size-3.5" />
                        </span>
                        {row.nameClash}
                    </p>
                )}
                {row.source === 'refused' && row.reason !== null && (
                    <p className="text-danger-ink text-caption flex items-start gap-1 text-pretty">
                        <span className="flex h-lh shrink-0 items-center">
                            <XCircle aria-hidden="true" className="size-3.5" />
                        </span>
                        {__('Can’t be used: :reason', { reason: row.reason })}
                    </p>
                )}
                <RowLine title={row.plural} items={lineActions(row, locked)} />
            </div>
        </div>
    );
}

const COUNT_LINK =
    'text-accent-ink focus-visible:outline-ring inline-flex min-h-(--ctl-h-sm) items-center justify-self-start rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2';

function ItemsCell({ row }: { row: TypeRow }) {
    if (row.items === 0) {
        return <span className="text-ink-3">{__('None')}</span>;
    }

    const trashed = __n(':count in the trash|:count in the trash', row.trashed);

    return (
        <span className="grid tabular-nums">
            {row.listUrl === null ? (
                row.items.toLocaleString()
            ) : (
                <Link href={row.listUrl} className={COUNT_LINK}>
                    {row.items.toLocaleString()}
                </Link>
            )}
            {row.trashed > 0 &&
                (row.listUrl === null ? (
                    <span className="text-small text-ink-3">{trashed}</span>
                ) : (
                    <Link
                        href={`${row.listUrl}?tab=trash`}
                        className={`${COUNT_LINK} text-small`}
                    >
                        {trashed}
                    </Link>
                ))}
        </span>
    );
}

function SourceBadge({ source }: { source: TypeRow['source'] }) {
    if (source === 'plugin') {
        return <StatusPill tone="neutral">{__('Plugin')}</StatusPill>;
    }

    if (source === 'code') {
        return <StatusPill tone="neutral">{__('Code')}</StatusPill>;
    }

    if (source === 'stored') {
        return <StatusPill tone="info">{__('Made here')}</StatusPill>;
    }

    return <StatusPill tone="danger">{__('Can’t be used')}</StatusPill>;
}

/** A type made here with no items: the only kind that can go. */
function deletable(row: TypeRow, locked: boolean): boolean {
    return (
        !locked &&
        row.source !== 'code' &&
        row.plugin === null &&
        row.items === 0
    );
}

/** Under the name, always shown: View or Edit, Fields, and Open the list. */
function lineActions(row: TypeRow, locked: boolean): RowAction[] {
    const href = show.url(row.key);
    const items: RowAction[] = [
        row.source === 'code' || row.plugin !== null || locked
            ? { id: 'view', owner: 'core', label: __('View'), href }
            : {
                  id: 'edit',
                  owner: 'core',
                  label:
                      row.source === 'refused' ? __('Edit to fix') : __('Edit'),
                  href,
              },
    ];

    if (row.source === 'stored' && row.plugin === null && !locked) {
        items.push({
            id: 'fields',
            owner: 'core',
            label: __('Fields'),
            href: typeFields.url(row.key),
        });
    }

    if (row.listUrl !== null) {
        items.push({
            id: 'open',
            owner: 'core',
            label: __('Open :plural', { plural: row.plural.toLowerCase() }),
            href: row.listUrl,
        });
    }

    return items;
}

/** The "…" menu of a type that can go: Edit, then Delete, asked first. */
function menuActions(row: TypeRow): RowAction[] {
    return [
        {
            id: 'edit',
            owner: 'core',
            label: __('Edit'),
            href: show.url(row.key),
        },
        {
            id: 'delete',
            owner: 'core',
            label: __('Delete…'),
            destructive: true,
            form: TypesController.destroy.form(row.key),
            wrap: (button) => (
                <ConfirmAction
                    title={__('Delete :plural?', { plural: row.plural })}
                    description={__('It has no items, so only the type goes.')}
                    confirmLabel={__('Delete :plural', { plural: row.plural })}
                >
                    {button}
                </ConfirmAction>
            ),
        },
    ];
}
