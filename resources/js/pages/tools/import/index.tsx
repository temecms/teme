import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import { ConnectForm } from '@/components/import/connect-form';
import { ImportWays, type ImportWay } from '@/components/import/ways';
import { UploadForm } from '@/components/import/upload-form';
import { DataTable } from '@/components/list/data-table';
import { Button } from '@/components/ui/button';
import type { Column } from '@/components/list/data-table';
import { ListPage } from '@/components/patterns/list-page';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as importerIndex } from '@/routes/tools/importer';
import type { ImportIndexPage, ImportSiteRow } from '@/types/import';

const TONES: Record<
    ImportSiteRow['state'],
    'info' | 'ok' | 'warn' | 'neutral'
> = {
    none: 'info',
    running: 'info',
    stopped: 'warn',
    finished: 'ok',
};

/** Tools › Import: the sites Teme imports from, and the two ways to add another. */
export default function ImportIndex({
    sites,
    companion,
    siteTimezone,
    maxFileMb,
}: ImportIndexPage) {
    const [way, setWay] = useState<ImportWay>(null);

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
                <div className="grid justify-items-start gap-1">
                    <Link
                        href={row.href}
                        className="text-ink font-semibold hover:underline hover:underline-offset-4"
                    >
                        {row.host}
                    </Link>
                    <span className="text-small text-ink-3">
                        {row.kind === 'file' && row.uploadedAt
                            ? __('WordPress · export file, uploaded :date', {
                                  date: formatInZone(
                                      row.uploadedAt,
                                      siteTimezone,
                                  ),
                              })
                            : __('WordPress · Teme Companion :version', {
                                  version: row.pluginVersion,
                              })}
                    </span>
                </div>
            ),
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill tone={TONES[row.state]}>
                        {row.stateLabel}
                    </StatusPill>
                    {row.forgotten && (
                        <StatusPill tone="neutral" flat>
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
                row.lastImportAt === null ? (
                    <span className="text-ink-2">{__('Never')}</span>
                ) : (
                    formatInZone(row.lastImportAt, siteTimezone)
                ),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            cell: (row) => (
                <div className="flex justify-end gap-2">
                    {row.forgotten && (
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={row.href}
                                aria-label={__('Connect :host again', {
                                    host: row.host,
                                })}
                            >
                                {__('Connect again')}
                            </Link>
                        </Button>
                    )}
                    <Button size="sm" variant="outline" asChild>
                        <Link
                            href={row.href}
                            aria-label={__('Open :host', { host: row.host })}
                        >
                            {__('Open')}
                        </Link>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={__('Import')} />

            <ListPage
                title={__('Import')}
                description={__(
                    'Move a WordPress site into Teme: from the live site, or from its export file. Every old address keeps working.',
                )}
            >
                {sites.length > 0 && (
                    <section
                        aria-labelledby="import-sites"
                        className="grid gap-3"
                    >
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2
                                id="import-sites"
                                className="text-lead font-semibold"
                            >
                                {__('Sites')}
                            </h2>
                            <span className="text-small text-ink-2">
                                {__(
                                    'Open a site to see its plan, run a catch-up, or read its last report.',
                                )}
                            </span>
                        </div>
                        <DataTable
                            caption={__('Sites')}
                            columns={columns}
                            rows={sites}
                            rowKey={(row) => row.id}
                        />
                    </section>
                )}

                <ImportWays open={way} onOpen={setWay} />

                {way === 'connect' && (
                    <ConnectForm
                        address={null}
                        userName={null}
                        companion={companion}
                    />
                )}

                {way === 'upload' && <UploadForm maxFileMb={maxFileMb} />}
            </ListPage>
        </>
    );
}
