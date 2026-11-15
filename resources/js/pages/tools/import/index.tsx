import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { ConnectForm } from '@/components/import/connect-form';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { ListPage } from '@/components/patterns/list-page';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
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
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Import'), href: importerIndex() },
        ],
    });

    const columns: Column<ImportSiteRow>[] = [
        {
            id: 'site',
            owner: 'core',
            header: __('Site'),
            cell: (row) => (
                <Link
                    href={row.href}
                    className="text-ink font-medium underline underline-offset-4"
                >
                    {row.address}
                </Link>
            ),
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    <span>{row.stateLabel}</span>
                    {row.forgotten && (
                        <StatusPill tone="neutral">
                            {__('Password forgotten')}
                        </StatusPill>
                    )}
                </div>
            ),
        },
        {
            id: 'last',
            owner: 'core',
            header: __('Last import'),
            cell: (row) =>
                row.lastImportAt === null
                    ? __('Never')
                    : formatInZone(row.lastImportAt, siteTimezone),
        },
    ];

    return (
        <>
            <Head title={__('Import')} />

            <ListPage
                title={__('Import')}
                description={__(
                    'Move a WordPress site into Teme: its posts, pages, media, people, comments and menus. Every old address keeps working.',
                )}
            >
                {sites.length > 0 && (
                    <section
                        aria-labelledby="import-sites"
                        className="grid gap-3"
                    >
                        <h2
                            id="import-sites"
                            className="text-lead font-semibold"
                        >
                            {__('Sites')}
                        </h2>
                        <DataTable
                            caption={__('Sites')}
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
            </ListPage>
        </>
    );
}
