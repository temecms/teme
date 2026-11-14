import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { formatInZone } from '@/lib/zoned-time';
import type { ImportRunRow } from '@/types/import';

/** A site's imports, newest first, each leading to its own report (I-345). */
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
            header: 'Import',
            cell: (row) => `${row.kindLabel}, ${row.stateLabel.toLowerCase()}`,
        },
        {
            id: 'started',
            owner: 'core',
            header: 'Started',
            cell: (row) => formatInZone(row.startedAt, siteTimezone),
        },
        {
            id: 'result',
            owner: 'core',
            header: 'Result',
            cell: (row) =>
                `${row.counts.created.toLocaleString()} created, ${row.counts.failed.toLocaleString()} failed`,
        },
        {
            id: 'report',
            owner: 'core',
            header: <span className="sr-only">Report</span>,
            cell: (row) =>
                row.id === shownId ? null : (
                    <Link
                        href={row.href}
                        aria-label={`Show the report of the import started ${formatInZone(row.startedAt, siteTimezone)}`}
                        className="text-foreground underline underline-offset-4"
                    >
                        Show its report
                    </Link>
                ),
        },
    ];

    return (
        <section aria-labelledby="import-runs" className="max-w-3xl space-y-3">
            <h2 id="import-runs" className="text-base font-medium">
                Imports of this site
            </h2>
            <DataTable
                caption="Imports of this site"
                columns={columns}
                rows={runs}
                rowKey={(row) => row.id}
            />
        </section>
    );
}
