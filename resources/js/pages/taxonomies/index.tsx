import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Download, Plus, Tags, XCircle } from 'lucide-react';
import TaxonomiesController from '@/actions/App/Http/Admin/TaxonomiesController';
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
import { StatusPill } from '@/components/ui/status-pill';
import { useSectionCrumb } from '@/lib/crumbs';
import { __ } from '@/lib/i18n';
import { index as taxonomiesIndex, show } from '@/routes/taxonomies';
import type { TaxonomiesIndexPage, TaxonomyRow } from '@/types';

/** "Films", "Posts and Films", "Movies, TV Shows and Games". */
function andList(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : __(':list and :last', {
              list: names.slice(0, -1).join(', '),
              last: names[names.length - 1],
          });
}

/**
 * Site › Taxonomies: every taxonomy the site keeps, code's and the ones made
 * here, with a refused one's reason and the way to open, change or delete it.
 */
export default function TaxonomiesIndex({
    locked,
    taxonomies,
}: TaxonomiesIndexPage) {
    const title = __('Taxonomies');
    const siteCrumb = useSectionCrumb('site');

    setLayoutProps({
        breadcrumbs: [siteCrumb, { title, href: taxonomiesIndex() }],
    });

    const columns: Column<TaxonomyRow>[] = [
        {
            id: 'taxonomy',
            owner: 'core',
            header: __('Taxonomy'),
            cell: (row) => <TaxonomyCell row={row} locked={locked} />,
        },
        {
            id: 'arranged',
            owner: 'core',
            header: __('Arranged'),
            className: 'hidden md:table-cell',
            cell: (row) => (row.hierarchical ? __('Nested') : __('Flat')),
        },
        {
            id: 'used-by',
            owner: 'core',
            header: __('Used by'),
            className: 'hidden sm:table-cell',
            cell: (row) =>
                row.usedBy.length === 0 ? __('None') : andList(row.usedBy),
        },
        {
            id: 'public',
            owner: 'core',
            header: __('Public address'),
            className: 'hidden md:table-cell',
            cell: (row) =>
                row.address === null ? (
                    <span className="text-ink-3">
                        {__('None: no public pages')}
                    </span>
                ) : (
                    <code className="text-caption text-ink-2 font-mono break-all">
                        {row.address}
                    </code>
                ),
        },
        {
            id: 'terms',
            owner: 'core',
            header: __('Terms'),
            className: 'text-end tabular-nums',
            cell: (row) => row.terms.toLocaleString(),
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
                'The ways this site groups its content, such as categories and tags. Taxonomies declared by code are shown, but can’t be changed here.',
            )}
            actions={
                <>
                    <Button variant="neutral" asChild>
                        <a
                            href={TypesController.download.url()}
                            download
                            data-test="taxonomies-download"
                        >
                            <Download aria-hidden="true" />
                            {__('Download teme-types.json')}
                        </a>
                    </Button>
                    {!locked && (
                        <Button asChild variant="primary">
                            <Link
                                href={TaxonomiesController.create.url()}
                                data-test="taxonomies-add"
                            >
                                <Plus aria-hidden="true" />
                                {__('Add new taxonomy')}
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
                        'Taxonomies are locked on this site (TEME_TYPES_LOCKED): change them where they’re made, and bring them here with :command. You can still look at each taxonomy, and download teme-types.json.',
                        { command: 'php artisan teme:types:import' },
                    )}
                </Notice>
            )}

            <DataTable
                columns={columns}
                rows={taxonomies}
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

function TaxonomyCell({ row, locked }: { row: TaxonomyRow; locked: boolean }) {
    return (
        <div className="flex min-w-0 items-start gap-3">
            <IconTile icon={Tags} />
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

function SourceBadge({ source }: { source: TaxonomyRow['source'] }) {
    if (source === 'code') {
        return <StatusPill tone="neutral">{__('Code')}</StatusPill>;
    }

    if (source === 'stored') {
        return <StatusPill tone="info">{__('Made here')}</StatusPill>;
    }

    return <StatusPill tone="danger">{__('Can’t be used')}</StatusPill>;
}

/** A taxonomy made here with no terms: the only kind that can go. */
function deletable(row: TaxonomyRow, locked: boolean): boolean {
    return !locked && row.source !== 'code' && row.terms === 0;
}

/** Under the name, always shown: View or Edit, and Open the terms. */
function lineActions(row: TaxonomyRow, locked: boolean): RowAction[] {
    const href = show.url(row.key);
    const items: RowAction[] = [
        row.source === 'code' || locked
            ? { id: 'view', owner: 'core', label: __('View'), href }
            : {
                  id: 'edit',
                  owner: 'core',
                  label:
                      row.source === 'refused' ? __('Edit to fix') : __('Edit'),
                  href,
              },
    ];

    if (row.termsUrl !== null) {
        items.push({
            id: 'open',
            owner: 'core',
            label: __('Open :plural', { plural: row.plural }),
            href: row.termsUrl,
        });
    }

    return items;
}

/** The "…" menu of a taxonomy that can go: Edit, then Delete, asked first. */
function menuActions(row: TaxonomyRow): RowAction[] {
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
            form: TaxonomiesController.destroy.form(row.key),
            wrap: (button) => (
                <ConfirmAction
                    title={__('Delete :plural?', { plural: row.plural })}
                    description={__(
                        'It has no terms, so only the taxonomy goes.',
                    )}
                    confirmLabel={__('Delete :plural', { plural: row.plural })}
                >
                    {button}
                </ConfirmAction>
            ),
        },
    ];
}
