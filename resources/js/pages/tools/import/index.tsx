import { Head, Link } from '@inertiajs/react';
import { ConnectForm } from '@/components/import/connect-form';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { PageHeader } from '@/components/list/page-header';
import { Badge } from '@/components/ui/badge';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as importerIndex } from '@/routes/tools/importer';
import type { ImportIndexPage, ImportSiteRow } from '@/types/import';

/** Tools › Import (I-227): the sites Teme imports from, and the form that connects another. */
export default function ImportIndex({
    sites,
    companion,
    siteTimezone,
}: ImportIndexPage) {
    const columns: Column<ImportSiteRow>[] = [
        {
            id: 'site',
            owner: 'core',
            header: 'Site',
            cell: (row) => (
                <Link
                    href={row.href}
                    className="text-foreground font-medium underline underline-offset-4"
                >
                    {row.address}
                </Link>
            ),
        },
        {
            id: 'state',
            owner: 'core',
            header: 'State',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    <span>{row.stateLabel}</span>
                    {row.forgotten && (
                        <Badge variant="outline">Password forgotten</Badge>
                    )}
                </div>
            ),
        },
        {
            id: 'last',
            owner: 'core',
            header: 'Last import',
            cell: (row) =>
                row.lastImportAt === null
                    ? 'Never'
                    : formatInZone(row.lastImportAt, siteTimezone),
        },
    ];

    return (
        <>
            <Head title="Import" />

            <div className="space-y-8 p-6">
                <PageHeader
                    title="Import"
                    description="Move a WordPress site into Teme: its posts, pages, media, people, comments and menus. Every old address keeps working."
                />

                {sites.length > 0 && (
                    <section
                        aria-labelledby="import-sites"
                        className="space-y-3"
                    >
                        <h2 id="import-sites" className="text-base font-medium">
                            Sites
                        </h2>
                        <DataTable
                            caption="Sites"
                            columns={columns}
                            rows={sites}
                            rowKey={(row) => row.id}
                        />
                    </section>
                )}

                <ConnectForm
                    address={null}
                    userName={null}
                    companion={companion}
                />
            </div>
        </>
    );
}

ImportIndex.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Import', href: importerIndex() },
    ],
};
