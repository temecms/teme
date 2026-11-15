import { Form, Link } from '@inertiajs/react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import { ConfirmAction } from '@/components/confirm-action';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { show } from '@/routes/tools/importer';
import type {
    ImportRunView,
    ImportSite,
    RunReport as RunReportView,
} from '@/types/import';

type TypeRow = RunReportView['types'][number];
type MessageRow = RunReportView['messages'][number];

/** The four counts' headings, looked up as they are drawn. */
function countHeading(key: 'created' | 'updated' | 'skipped' | 'failed') {
    return {
        created: __('Created'),
        updated: __('Updated'),
        skipped: __('Skipped'),
        failed: __('Failed'),
    }[key];
}

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
        {
            id: 'kind',
            owner: 'core',
            header: __('Kind'),
            cell: (row) => row.label,
        },
        ...(['created', 'updated', 'skipped', 'failed'] as const).map(
            (key): Column<TypeRow> => ({
                id: key,
                owner: 'core',
                header: countHeading(key),
                className: 'text-right tabular-nums',
                cell: (row) => number(row[key]),
            }),
        ),
    ];

    const messageColumns: Column<MessageRow>[] = [
        {
            id: 'message',
            owner: 'core',
            header: __('Message'),
            cell: (row) => row.words,
        },
        {
            id: 'level',
            owner: 'core',
            header: __('Level'),
            cell: (row) => (
                <StatusPill tone={row.level === 'error' ? 'danger' : 'warn'}>
                    {row.levelLabel}
                </StatusPill>
            ),
        },
        {
            id: 'count',
            owner: 'core',
            header: __('How many'),
            className: 'text-right tabular-nums',
            cell: (row) => number(row.count),
        },
    ];

    return (
        <Panel
            data-test="import-report"
            aria-labelledby="import-report-heading"
        >
            <PanelHeader>
                <h2
                    id="import-report-heading"
                    className="text-lead font-semibold"
                >
                    {finished ? __('Report') : __('Report so far')}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body gap-6">
                {finished && latest && (
                    <p>
                        {__(
                            'Finished :date. :created created, :updated updated, :skipped skipped, :failed failed, :warnings with warnings.',
                            {
                                date:
                                    run.endedAt === null
                                        ? ''
                                        : formatInZone(
                                              run.endedAt,
                                              siteTimezone,
                                          ),
                                created: number(counts.created),
                                updated: number(counts.updated),
                                skipped: number(counts.skipped),
                                failed: number(counts.failed),
                                warnings: number(counts.warnings),
                            },
                        )}
                    </p>
                )}
                {finished && !latest && (
                    <div className="flex flex-wrap items-center gap-3">
                        <p>
                            {__('Report of the import started :date.', {
                                date: formatInZone(run.startedAt, siteTimezone),
                            })}
                        </p>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={show({ source: site.id })}>
                                {__('Show the latest import')}
                            </Link>
                        </Button>
                    </div>
                )}

                {report.ready !== null && (
                    <Notice tone={report.ready ? 'ok' : 'warn'}>
                        <p>
                            {report.ready
                                ? __(
                                      'Ready to switch: nothing failed and every old address answers on Teme.',
                                  )
                                : __n(
                                      'Not ready to switch yet: :count problem needs attention. Fix them on WordPress or here, then catch up.|Not ready to switch yet: :count problems need attention. Fix them on WordPress or here, then catch up.',
                                      report.problems,
                                      { count: number(report.problems) },
                                  )}
                        </p>
                    </Notice>
                )}

                <section aria-labelledby="import-came" className="grid gap-3">
                    <h3 id="import-came" className="text-body font-semibold">
                        {__('What came across')}
                    </h3>
                    {report.types.length === 0 ? (
                        <p>{__('Nothing came across in this import.')}</p>
                    ) : (
                        <DataTable
                            caption={__('What came across')}
                            columns={typeColumns}
                            rows={report.types}
                            rowKey={(row) => row.type}
                        />
                    )}
                </section>

                <section
                    aria-labelledby="import-messages"
                    className="grid gap-3"
                >
                    <h3
                        id="import-messages"
                        className="text-body font-semibold"
                    >
                        {__('Warnings and errors')}
                    </h3>
                    {report.messages.length === 0 ? (
                        <p>{__('No warnings or errors.')}</p>
                    ) : (
                        <DataTable
                            caption={__('Warnings and errors')}
                            columns={messageColumns}
                            rows={report.messages}
                            rowKey={(row) => row.code}
                        />
                    )}
                    {report.more && (
                        <p className="text-small text-ink-2">
                            {__('The full report lists the rest.')}
                        </p>
                    )}
                </section>

                {downloads !== null && (
                    <div className="grid gap-2">
                        <ul className="flex flex-wrap gap-x-6 gap-y-1">
                            <li>
                                <a
                                    href={downloads.everything}
                                    download
                                    data-test="import-download"
                                    className="text-ink inline-block min-h-6 underline underline-offset-4"
                                >
                                    {__('Download the full report')}
                                </a>
                            </li>
                            <li>
                                <a
                                    href={downloads.attention}
                                    download
                                    data-test="import-download"
                                    className="text-ink inline-block min-h-6 underline underline-offset-4"
                                >
                                    {__('Download what needs attention')}
                                </a>
                            </li>
                        </ul>
                        <p className="text-small text-ink-2">
                            {__(
                                'The full report has a line for every item and what happened to it. The other has only what failed or has a warning.',
                            )}
                        </p>
                    </div>
                )}

                {finished && latest && !site.forgotten && (
                    <>
                        <section
                            aria-labelledby="import-catch-up"
                            className="grid justify-items-start gap-2"
                        >
                            <h3
                                id="import-catch-up"
                                className="text-body font-semibold"
                            >
                                {__('Catch up')}
                            </h3>
                            <p>
                                {__(
                                    'Brings across what changed on WordPress since :date: new and edited posts and pages, new comments and new redirects. Anything deleted on WordPress since is listed, never deleted here.',
                                    {
                                        date:
                                            run.endedAt === null
                                                ? __('then')
                                                : formatInZone(
                                                      run.endedAt,
                                                      siteTimezone,
                                                  ),
                                    },
                                )}
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
                                        {__('Catch up')}
                                    </Button>
                                )}
                            </Form>
                        </section>

                        <div className="grid justify-items-start gap-2">
                            <p>
                                {__(
                                    'When you’ve switched to Teme, forget this site, then revoke its application password in WordPress.',
                                )}
                            </p>
                            <Form
                                {...ImportSourceController.forget.form({
                                    source: site.id,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                <ConfirmAction
                                    title={__(
                                        'Forget this site’s application password?',
                                    )}
                                    description={__(
                                        'Teme deletes its copy of the application password. What was imported stays, and so does this site’s report. To import from it again, connect it again.',
                                    )}
                                    confirmLabel={__('Forget this site')}
                                    confirmVariant="destructive"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        data-test="import-forget"
                                    >
                                        {__('Forget this site')}
                                    </Button>
                                </ConfirmAction>
                            </Form>
                        </div>
                    </>
                )}
            </PanelBody>
        </Panel>
    );
}
