import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import type { ImportRunRow } from '@/types/import';

const TONES: Record<ImportRunRow['state'], 'ok' | 'warn' | 'info'> = {
    finished: 'ok',
    stopped: 'warn',
    running: 'info',
};

/** A site's past imports, newest first, each leading to its own report (I-345). */
export function SiteRuns({
    runs,
    shownId,
    siteTimezone,
}: {
    runs: ImportRunRow[];
    shownId: string | null;
    siteTimezone: string;
}) {
    const columns: Column<ImportRunRow>[] = [
        {
            id: 'import',
            owner: 'core',
            header: __('Import'),
            cell: (row) => (
                <div className="grid gap-1">
                    <span className="font-semibold">
                        {formatInZone(row.startedAt, siteTimezone)}
                    </span>
                    <span className="text-small text-ink-3">
                        {row.id === shownId
                            ? __('The one shown above')
                            : row.kindLabel}
                    </span>
                </div>
            ),
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => (
                <StatusPill tone={TONES[row.state]}>
                    {row.stateLabel}
                </StatusPill>
            ),
        },
        {
            id: 'result',
            owner: 'core',
            header: __('Result'),
            cell: (row) =>
                __(':created created, :failed failed', {
                    created: row.counts.created.toLocaleString(),
                    failed: row.counts.failed.toLocaleString(),
                }),
        },
        {
            id: 'report',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            cell: (row) =>
                row.id === shownId ? null : (
                    <div className="flex justify-end">
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={row.href}
                                aria-label={__(
                                    'Show the report of the import started :date',
                                    {
                                        date: formatInZone(
                                            row.startedAt,
                                            siteTimezone,
                                        ),
                                    },
                                )}
                            >
                                {__('Show')}
                            </Link>
                        </Button>
                    </div>
                ),
        },
    ];

    return (
        <section aria-labelledby="import-runs" className="grid gap-3">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="import-runs" className="text-lead font-semibold">
                    {__('Past imports')}
                </h2>
                <span className="text-small text-ink-2">
                    {__('Newest first. Each has its own report.')}
                </span>
            </div>
            <DataTable
                caption={__('Past imports of this site')}
                columns={columns}
                rows={runs}
                rowKey={(row) => row.id}
            />
        </section>
    );
}
