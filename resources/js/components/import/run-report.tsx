import { Form, Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatInZone } from '@/lib/zoned-time';
import { show } from '@/routes/tools/importer';
import type {
    ImportRunView,
    ImportSite,
    RunReport as RunReportView,
} from '@/types/import';

type TypeRow = RunReportView['types'][number];
type MessageRow = RunReportView['messages'][number];

/**
 * An import's report (I-345): what came across by kind, the warnings and
 * errors grouped, the two files, and, for the site's latest import, Catch up
 * and Forget this site. Drawn too under a stopped import, as its report so far.
 */
export function RunReport({
    site,
    run,
    report,
    downloads,
    latest,
    siteTimezone,
}: {
    site: ImportSite;
    run: ImportRunView;
    report: RunReportView;
    downloads: { everything: string; attention: string } | null;
    latest: boolean;
    siteTimezone: string;
}) {
    const finished = run.state === 'finished';
    const number = (value: number) => value.toLocaleString();
    const counts = run.counts;

    const typeColumns: Column<TypeRow>[] = [
        { id: 'kind', owner: 'core', header: 'Kind', cell: (row) => row.label },
        ...(['created', 'updated', 'skipped', 'failed'] as const).map(
            (key): Column<TypeRow> => ({
                id: key,
                owner: 'core',
                header: key.charAt(0).toUpperCase() + key.slice(1),
                className: 'text-right tabular-nums',
                cell: (row) => number(row[key]),
            }),
        ),
    ];

    const messageColumns: Column<MessageRow>[] = [
        {
            id: 'message',
            owner: 'core',
            header: 'Message',
            cell: (row) => row.words,
        },
        {
            id: 'level',
            owner: 'core',
            header: 'Level',
            cell: (row) => (
                <Badge
                    variant={row.level === 'error' ? 'destructive' : 'outline'}
                >
                    {row.levelLabel}
                </Badge>
            ),
        },
        {
            id: 'count',
            owner: 'core',
            header: 'How many',
            className: 'text-right tabular-nums',
            cell: (row) => number(row.count),
        },
    ];

    return (
        <section
            data-test="import-report"
            aria-labelledby="import-report-heading"
            className="max-w-3xl space-y-6"
        >
            <div className="space-y-2">
                <h2
                    id="import-report-heading"
                    className="text-base font-medium"
                >
                    {finished ? 'Report' : 'Report so far'}
                </h2>
                {finished && latest && (
                    <p className="text-[13px]">
                        Finished{' '}
                        {run.endedAt === null
                            ? ''
                            : formatInZone(run.endedAt, siteTimezone)}
                        . {number(counts.created)} created,{' '}
                        {number(counts.updated)} updated,{' '}
                        {number(counts.skipped)} skipped,{' '}
                        {number(counts.failed)} failed,{' '}
                        {number(counts.warnings)} with warnings.
                    </p>
                )}
                {finished && !latest && (
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-[13px]">
                            Report of the import started{' '}
                            {formatInZone(run.startedAt, siteTimezone)}.
                        </p>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={show({ source: site.id })}>
                                Show the latest import
                            </Link>
                        </Button>
                    </div>
                )}
            </div>

            {report.ready !== null && (
                <div
                    role="note"
                    className="border-border flex gap-3 rounded-md border p-4 text-sm"
                >
                    <Info
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0"
                    />
                    <p>
                        {report.ready
                            ? 'Ready to switch: nothing failed and every old address answers on Teme.'
                            : `Not ready to switch yet: ${
                                  report.problems === 1
                                      ? '1 problem needs'
                                      : `${number(report.problems)} problems need`
                              } attention. Fix them on WordPress or here, then catch up.`}
                    </p>
                </div>
            )}

            <div className="space-y-3">
                <h3 className="text-base font-medium">What came across</h3>
                {report.types.length === 0 ? (
                    <p className="text-[13px]">
                        Nothing came across in this import.
                    </p>
                ) : (
                    <DataTable
                        caption="What came across"
                        columns={typeColumns}
                        rows={report.types}
                        rowKey={(row) => row.type}
                    />
                )}
            </div>

            <div className="space-y-3">
                <h3 className="text-base font-medium">Warnings and errors</h3>
                {report.messages.length === 0 ? (
                    <p className="text-[13px]">No warnings or errors.</p>
                ) : (
                    <DataTable
                        caption="Warnings and errors"
                        columns={messageColumns}
                        rows={report.messages}
                        rowKey={(row) => row.code}
                    />
                )}
                {report.more && (
                    <p className="text-muted-foreground text-[13px]">
                        The full report lists the rest.
                    </p>
                )}
            </div>

            {downloads !== null && (
                <div className="space-y-2 text-[13px]">
                    <ul className="flex flex-wrap gap-x-6 gap-y-1">
                        <li>
                            <a
                                href={downloads.everything}
                                download
                                data-test="import-download"
                                className="text-foreground inline-block min-h-6 underline underline-offset-4"
                            >
                                Download the full report
                            </a>
                        </li>
                        <li>
                            <a
                                href={downloads.attention}
                                download
                                data-test="import-download"
                                className="text-foreground inline-block min-h-6 underline underline-offset-4"
                            >
                                Download what needs attention
                            </a>
                        </li>
                    </ul>
                    <p className="text-muted-foreground">
                        The full report has a line for every item and what
                        happened to it. The other has only what failed or has a
                        warning.
                    </p>
                </div>
            )}

            {finished && latest && !site.forgotten && (
                <>
                    <div className="space-y-2">
                        <h3 className="text-base font-medium">Catch up</h3>
                        <p className="text-[13px]">
                            Brings across what changed on WordPress since{' '}
                            {run.endedAt === null
                                ? 'then'
                                : formatInZone(run.endedAt, siteTimezone)}
                            : new and edited posts and pages, new comments and
                            new redirects. Anything deleted on WordPress since
                            is listed, never deleted here.
                        </p>
                        <Form
                            {...ImportRunController.catchUp.form({
                                source: site.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    data-test="import-catch-up"
                                    disabled={processing}
                                >
                                    Catch up
                                </Button>
                            )}
                        </Form>
                    </div>

                    <div className="space-y-2">
                        <p className="text-[13px]">
                            When you’ve switched to Teme, forget this site, then
                            revoke its application password in WordPress.
                        </p>
                        <Form
                            {...ImportSourceController.forget.form({
                                source: site.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            <ConfirmAction
                                title="Forget this site’s application password?"
                                description="Teme deletes its copy of the application password. What was imported stays, and so does this site’s report. To import from it again, connect it again."
                                confirmLabel="Forget this site"
                                confirmVariant="destructive"
                            >
                                <Button
                                    type="submit"
                                    variant="outline"
                                    data-test="import-forget"
                                >
                                    Forget this site
                                </Button>
                            </ConfirmAction>
                        </Form>
                    </div>
                </>
            )}
        </section>
    );
}
