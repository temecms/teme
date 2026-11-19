import { __ } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { Download, TriangleAlert, XCircle } from 'lucide-react';
import TypesController from '@/actions/App/Http/Admin/TypesController';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { RowLinks } from '@/components/list/row-links';
import { ListPage } from '@/components/patterns/list-page';
import type { RowAction } from '@/components/list/row-actions';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { PluginMark } from '@/components/ui/plugin-mark';
import { StatusPill } from '@/components/ui/status-pill';
import { navIcon } from '@/lib/nav-icons';
import { index as typesIndex, show } from '@/routes/types';
import type { TypeRow, TypesIndexPage } from '@/types';

const sources = {
    code: { label: 'Code', tone: 'neutral' },
    stored: { label: 'Made here', tone: 'info' },
    refused: { label: 'Can’t be used', tone: 'danger' },
} as const;

/**
 * Site › Types: every type the site keeps, code's and the ones made here,
 * with a refused type's reason and the way to open, change or delete it.
 */
export default function TypesIndex({ locked, types }: TypesIndexPage) {
    const columns: Column<TypeRow>[] = [
        {
            id: 'type',
            owner: 'core',
            header: 'Type',
            cell: (row) => <TypeCell row={row} locked={locked} />,
        },
        {
            id: 'admin',
            owner: 'core',
            header: 'Admin address',
            className: 'hidden lg:table-cell',
            cell: (row) => <code>{`/admin/${row.segment}`}</code>,
        },
        {
            id: 'public',
            owner: 'core',
            header: 'Public address',
            className: 'hidden sm:table-cell',
            cell: (row) =>
                row.public && row.pattern !== null ? (
                    <code>{row.pattern}</code>
                ) : (
                    'None: not public'
                ),
        },
        {
            id: 'items',
            owner: 'core',
            header: 'Items',
            cell: (row) => <ItemsCell row={row} />,
        },
        {
            id: 'source',
            owner: 'core',
            header: 'Source',
            className: 'hidden sm:table-cell',
            cell: (row) => <SourceBadge source={row.source} />,
        },
    ];

    return (
        <ListPage
            title="Types"
            description="The kinds of content this site keeps. Types declared by code are shown, but can’t be changed here."
            actions={
                <>
                    {!locked && (
                        <Button asChild>
                            <Link
                                href={TypesController.create.url()}
                                data-test="types-add"
                            >
                                Add new type
                            </Link>
                        </Button>
                    )}
                    <Button variant="neutral" asChild>
                        <a
                            href={TypesController.download.url()}
                            download
                            data-test="types-download"
                        >
                            <Download aria-hidden className="size-4" />
                            Download teme-types.json
                        </a>
                    </Button>
                </>
            }
        >
            <Head title="Types" />

            {locked && (
                <Notice tone="info">
                    <p>
                        Types are locked on this site (TEME_TYPES_LOCKED):
                        change them where they’re made, and bring them here with{' '}
                        <code>php artisan teme:types:import</code>.
                    </p>
                    <p>
                        You can still look at each type, and download
                        teme-types.json.
                    </p>
                </Notice>
            )}

            <DataTable
                columns={columns}
                rows={types}
                rowKey={(row) => row.key}
                caption="Types"
                rowProps={(row) =>
                    row.source === 'refused'
                        ? { className: 'bg-danger-soft' }
                        : {}
                }
            />
        </ListPage>
    );
}

TypesIndex.layout = {
    breadcrumbs: [{ title: 'Types', href: typesIndex() }],
};

function TypeCell({ row, locked }: { row: TypeRow; locked: boolean }) {
    const Icon = navIcon(row.icon);

    return (
        <div className="flex min-w-0 items-start gap-3">
            <span className="bg-surface-2 flex size-8 shrink-0 items-center justify-center rounded-md border">
                <Icon aria-hidden className="size-4" />
            </span>
            <div className="min-w-0">
                <Link
                    href={show.url(row.key)}
                    className="text-accent-ink font-medium hover:underline"
                >
                    {row.plural}
                </Link>
                <div className="text-ink-2 flex flex-wrap items-center gap-2">
                    <code className="text-caption break-all">{row.key}</code>
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
                    <p className="text-danger-ink text-caption mt-1 flex items-start gap-1 text-pretty">
                        <span className="flex h-lh shrink-0 items-center">
                            <XCircle aria-hidden className="size-3.5" />
                        </span>
                        Can’t be used: {row.reason}
                    </p>
                )}
                <RowLinks
                    items={actions(row, locked)}
                    label={`Actions for ${row.plural}`}
                    more={`More actions for ${row.plural}`}
                />
            </div>
        </div>
    );
}

function ItemsCell({ row }: { row: TypeRow }) {
    if (row.items === 0) {
        return 'None';
    }

    return (
        <span className="grid">
            {row.listUrl === null ? (
                row.items
            ) : (
                <Link
                    href={row.listUrl}
                    className="text-accent-ink inline-flex min-h-(--ctl-h-sm) items-center hover:underline"
                >
                    {row.items}
                </Link>
            )}
            {row.trashed > 0 &&
                (row.listUrl === null ? (
                    <span>{row.trashed} in the trash</span>
                ) : (
                    <Link
                        href={`${row.listUrl}?tab=trash`}
                        className="text-accent-ink inline-flex min-h-(--ctl-h-sm) items-center hover:underline"
                    >
                        {row.trashed} in the trash
                    </Link>
                ))}
        </span>
    );
}

function SourceBadge({ source }: { source: TypeRow['source'] }) {
    if (source === 'plugin') {
        return <StatusPill tone="neutral">{__('Plugin')}</StatusPill>;
    }

    const pill = sources[source];

    return <StatusPill tone={pill.tone}>{pill.label}</StatusPill>;
}

function actions(row: TypeRow, locked: boolean): RowAction[] {
    const href = show.url(row.key);
    const items: RowAction[] = [
        row.source === 'code' || row.plugin !== null || locked
            ? { id: 'view', owner: 'core', label: 'View', href }
            : {
                  id: 'edit',
                  owner: 'core',
                  label: row.source === 'refused' ? 'Edit to fix' : 'Edit',
                  href,
              },
    ];

    if (
        !locked &&
        row.source !== 'code' &&
        row.plugin === null &&
        row.items === 0
    ) {
        items.push({
            id: 'delete',
            owner: 'core',
            label: 'Delete',
            destructive: true,
            form: TypesController.destroy.form(row.key),
            wrap: (button) => (
                <ConfirmAction
                    title={`Delete ${row.plural}?`}
                    description="It has no items, so only the type goes."
                    confirmLabel={`Delete ${row.plural}`}
                >
                    {button}
                </ConfirmAction>
            ),
        });
    }

    return items;
}
