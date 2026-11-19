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
import { Toggle } from '@/components/ui/toggle';
import { useState } from 'react';
import { FigureTiles } from '@/components/tools/figure-tiles';
import type { FigureTile } from '@/components/tools/figure-tiles';
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

    const [level, setLevel] = useState<'all' | 'error' | 'warning'>('all');
    const shownMessages = report.messages.filter(
        (row) => level === 'all' || row.level === level,
    );

    const typeColumns: Column<TypeRow>[] = [
        {
            id: 'kind',
            owner: 'core',
            header: __('Kind'),
            cell: (row) => row.label,
        },
        {
            id: 'across',
            owner: 'core',
            header: __('Came across'),
            className: 'text-right tabular-nums',
            cell: (row) => number(row.created + row.updated),
        },
        {
            id: 'warnings',
            owner: 'core',
            header: __('With warnings'),
            className: 'text-right tabular-nums',
            cell: (row) => (row.warnings === null ? '' : number(row.warnings)),
        },
        {
            id: 'failed',
            owner: 'core',
            header: __('Failed'),
            className: 'text-right tabular-nums',
            cell: (row) => number(row.failed),
        },
    ];
    const figures: FigureTile[] = [
        {
            key: 'across',
            label: __('Came across'),
            value: counts.created + counts.updated,
            tone: 'ok',
        },
        {
            key: 'warnings',
            label: __('With warnings'),
            value: counts.warnings,
            tone: 'warn',
        },
        {
            key: 'failed',
            label: __('Failed'),
            value: counts.failed,
            tone: 'danger',
        },
        ...(finished
            ? [
                  {
                      key: 'left',
                      label: __('Still to do'),
                      value: 0,
                      tone: 'neutral' as const,
                  },
              ]
            : []),
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
                <div className="grid gap-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-small text-ink-2">
                            {__('Report of the import started :date.', {
                                date: formatInZone(run.startedAt, siteTimezone),
                            })}
                        </p>
                        {finished && !latest && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={show({ source: site.id })}>
                                    {__('Show the latest import')}
                                </Link>
                            </Button>
                        )}
                    </div>
                    <FigureTiles
                        figures={figures}
                        label={__('The import’s counts')}
                        testId="import-report-counts"
                    />
                </div>

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
                        <>
                            <div
                                role="group"
                                aria-label={__('Show')}
                                data-test="import-message-levels"
                                className="flex flex-wrap gap-2"
                            >
                                {(
                                    [
                                        [
                                            'all',
                                            __('All'),
                                            report.levels.error +
                                                report.levels.warning,
                                        ],
                                        [
                                            'error',
                                            __('Errors'),
                                            report.levels.error,
                                        ],
                                        [
                                            'warning',
                                            __('Warnings'),
                                            report.levels.warning,
                                        ],
                                    ] as const
                                ).map(([key, label, count]) => (
                                    <Toggle
                                        key={key}
                                        variant="outline"
                                        size="sm"
                                        pressed={level === key}
                                        onPressedChange={() => setLevel(key)}
                                    >
                                        {label}
                                        <span className="text-ink-3 tabular-nums">
                                            {number(count)}
                                        </span>
                                    </Toggle>
                                ))}
                            </div>
                            <DataTable
                                caption={__('Warnings and errors')}
                                columns={messageColumns}
                                rows={shownMessages}
                                rowKey={(row) => row.code}
                            />
                        </>
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
