import { Head, Link } from '@inertiajs/react';
import { Download, XCircle } from 'lucide-react';
import TaxonomiesController from '@/actions/App/Http/Admin/TaxonomiesController';
import TypesController from '@/actions/App/Http/Admin/TypesController';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { PageHeader } from '@/components/list/page-header';
import { RowLinks } from '@/components/list/row-links';
import type { RowAction } from '@/components/list/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { navIcon } from '@/lib/nav-icons';
import { index as taxonomiesIndex, show } from '@/routes/taxonomies';
import type { TaxonomiesIndexPage, TaxonomyRow } from '@/types';

const sources = {
    code: { label: 'Code', variant: 'outline' },
    stored: { label: 'Made here', variant: 'secondary' },
    refused: { label: 'Can’t be used', variant: 'destructive' },
} as const;

/** "Films", "Posts and Films", "Movies, TV Shows and Games". */
function andList(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/**
 * Site › Taxonomies: every taxonomy the site keeps, code's and the ones made
 * here, with a refused one's reason and the way to open, change or delete it.
 */
export default function TaxonomiesIndex({
    locked,
    taxonomies,
}: TaxonomiesIndexPage) {
    const columns: Column<TaxonomyRow>[] = [
        {
            id: 'taxonomy',
            owner: 'core',
            header: 'Taxonomy',
            cell: (row) => <TaxonomyCell row={row} locked={locked} />,
        },
        {
            id: 'arranged',
            owner: 'core',
            header: 'Arranged',
            className: 'hidden md:table-cell',
            cell: (row) => (row.hierarchical ? 'Nested' : 'Flat'),
        },
        {
            id: 'used-by',
            owner: 'core',
            header: 'Used by',
            className: 'hidden sm:table-cell',
            cell: (row) =>
                row.usedBy.length === 0 ? 'None' : andList(row.usedBy),
        },
        {
            id: 'public',
            owner: 'core',
            header: 'Public address',
            className: 'hidden md:table-cell',
            cell: (row) =>
                row.address === null ? (
                    'None: no public pages'
                ) : (
                    <code>{row.address}</code>
                ),
        },
        {
            id: 'terms',
            owner: 'core',
            header: 'Terms',
            className: 'text-right',
            cell: (row) => <TermsCell row={row} />,
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
        <>
            <Head title="Taxonomies" />

            <div className="min-w-0 space-y-4 p-6">
                <PageHeader
                    title="Taxonomies"
                    description="The ways this site groups its content, such as categories and tags. Taxonomies declared by code are shown, but can’t be changed here."
                    action={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={TypesController.download.url()}
                                    download
                                    data-test="taxonomies-download"
                                >
                                    <Download aria-hidden className="size-4" />
                                    Download teme-types.json
                                </a>
                            </Button>
                            {!locked && (
                                <Button asChild>
                                    <Link
                                        href={TaxonomiesController.create.url()}
                                        data-test="taxonomies-add"
                                    >
                                        Add new taxonomy
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {locked && (
                    <div className="grid gap-1 text-sm">
                        <p>
                            Taxonomies are locked on this site
                            (TEME_TYPES_LOCKED): change them where they’re made,
                            and bring them here with{' '}
                            <code>php artisan teme:types:import</code>.
                        </p>
                        <p>
                            You can still look at each taxonomy, and download
                            teme-types.json.
                        </p>
                    </div>
                )}

                <DataTable
                    columns={columns}
                    rows={taxonomies}
                    rowKey={(row) => row.key}
                    caption="Taxonomies"
                    rowProps={(row) =>
                        row.source === 'refused'
                            ? { className: 'bg-danger-soft' }
                            : {}
                    }
                />
            </div>
        </>
    );
}

TaxonomiesIndex.layout = {
    breadcrumbs: [{ title: 'Taxonomies', href: taxonomiesIndex() }],
};

function TaxonomyCell({ row, locked }: { row: TaxonomyRow; locked: boolean }) {
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
                    <code className="text-xs break-all">{row.key}</code>
                    <span className="sm:hidden">
                        <SourceBadge source={row.source} />
                    </span>
                </div>
                {row.source === 'refused' && row.reason !== null && (
                    <p className="text-danger-ink mt-1 flex items-start gap-1 text-xs text-pretty">
                        <XCircle
                            aria-hidden
                            className="mt-0.5 size-3.5 shrink-0"
                        />
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

function TermsCell({ row }: { row: TaxonomyRow }) {
    if (row.termsUrl === null) {
        return <span className="font-mono tabular-nums">{row.terms}</span>;
    }

    return (
        <Link
            href={row.termsUrl}
            className="text-accent-ink inline-flex min-h-6 min-w-6 items-center justify-end font-mono tabular-nums hover:underline"
        >
            {row.terms.toLocaleString()}
        </Link>
    );
}

function SourceBadge({ source }: { source: TaxonomyRow['source'] }) {
    const badge = sources[source];

    return <Badge variant={badge.variant}>{badge.label}</Badge>;
}

function actions(row: TaxonomyRow, locked: boolean): RowAction[] {
    const href = show.url(row.key);
    const items: RowAction[] = [
        row.source === 'code' || locked
            ? { id: 'view', owner: 'core', label: 'View', href }
            : {
                  id: 'edit',
                  owner: 'core',
                  label: row.source === 'refused' ? 'Edit to fix' : 'Edit',
                  href,
              },
    ];

    if (row.termsUrl !== null) {
        items.push({
            id: 'open',
            owner: 'core',
            label: `Open ${row.plural}`,
            href: row.termsUrl,
        });
    }

    if (!locked && row.source !== 'code' && row.terms === 0) {
        items.push({
            id: 'delete',
            owner: 'core',
            label: 'Delete',
            destructive: true,
            form: TaxonomiesController.destroy.form(row.key),
            wrap: (button) => (
                <ConfirmAction
                    title={`Delete ${row.plural}?`}
                    description="It has no terms, so only the taxonomy goes."
                    confirmLabel={`Delete ${row.plural}`}
                >
                    {button}
                </ConfirmAction>
            ),
        });
    }

    return items;
}
