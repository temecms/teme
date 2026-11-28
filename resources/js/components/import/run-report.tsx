import { Form, Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import ImportFileController from '@/actions/App/Http/Admin/Tools/Import/ImportFileController';
import { ConfirmAction } from '@/components/confirm-action';
import { PicturePass } from '@/components/import/picture-pass';
import { UploadForm } from '@/components/import/upload-form';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { useState } from 'react';
import { __, __n } from '@/lib/i18n';
import { dayInZone, formatInZone } from '@/lib/zoned-time';
import { show } from '@/routes/tools/importer';
import type {
    ImportRunView,
    ImportSite,
    RunReport as RunReportView,
} from '@/types/import';

type TypeRow = RunReportView['types'][number];
type MessageRow = RunReportView['messages'][number];

/**
 * An import's report (I-345, issue 699): what came across by kind, warnings
 * grouped under plain names, the two downloads, and Catch up / Forget when
 * this is the site's latest finished import. Counts live on the progress card
 * above the tabs, so they are not repeated here.
 */
export function RunReport({
    site,
    run,
    report,
    downloads,
    latest,
    siteTimezone,
    maxFileMb,
    /** When false, skip the panel chrome — the Report tab already frames it. */
    framed = true,
}: {
    site: ImportSite;
    run: ImportRunView;
    report: RunReportView;
    downloads: { everything: string; attention: string } | null;
    latest: boolean;
    siteTimezone: string;
    maxFileMb: number;
    framed?: boolean;
}) {
    const finished = run.state === 'finished';
    const [newer, setNewer] = useState(false);
    const number = (value: number) => value.toLocaleString();

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

    const body = (
        <div className="text-body grid gap-6" data-test="import-report">
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

            {run.picturePass !== null && (
                <PicturePass run={run} pass={run.picturePass} />
            )}

            {report.ready !== null && (
                <Notice tone={report.ready ? 'ok' : 'warn'}>
                    <p>
                        {report.ready
                            ? __(
                                  'Ready to switch: nothing failed and every old address answers on Teme.',
                              )
                            : run.file !== null
                              ? __n(
                                    'Not ready to switch yet: :count problem needs attention. Fix them on WordPress and upload a newer file, or fix them here.|Not ready to switch yet: :count problems need attention. Fix them on WordPress and upload a newer file, or fix them here.',
                                    report.problems,
                                    { count: number(report.problems) },
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
                {report.pictures.copy + report.pictures.site > 0 && (
                    <p data-test="import-report-pictures">
                        {__(
                            'Pictures: :copy from the uploads copy, :site from the old site.',
                            {
                                copy: String(report.pictures.copy),
                                site: String(report.pictures.site),
                            },
                        )}
                    </p>
                )}
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

            <section aria-labelledby="import-messages" className="grid gap-3">
                <h3 id="import-messages" className="text-body font-semibold">
                    {__('Warnings and errors')}
                </h3>
                {report.messages.length === 0 ? (
                    <p>{__('No warnings or errors.')}</p>
                ) : (
                    <ul
                        className="grid gap-1"
                        data-test="import-message-groups"
                    >
                        {report.messages.map((row) => (
                            <MessageGroup
                                key={`${row.level}-${row.group}`}
                                row={row}
                                number={number}
                                seeItemsHref={downloads?.attention ?? null}
                            />
                        ))}
                    </ul>
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

            {finished && latest && run.file !== null && (
                <>
                    <section
                        aria-labelledby="import-bring-changed"
                        className="grid justify-items-start gap-2"
                    >
                        <h3
                            id="import-bring-changed"
                            className="text-body font-semibold"
                        >
                            {__('Bring what changed')}
                        </h3>
                        <p>
                            {__(
                                'Export the site again in WordPress and upload the new file. Teme brings what’s new or edited since this import and leaves alone what you’ve changed here. Anything missing from the new file is listed, never deleted here.',
                            )}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            data-test="import-upload-newer"
                            onClick={() => setNewer((open) => !open)}
                        >
                            {__('Upload a newer file')}
                        </Button>
                    </section>
                    {newer && <UploadForm maxFileMb={maxFileMb} />}

                    {!run.file.deleted && (
                        <section
                            aria-labelledby="import-delete-file"
                            className="grid justify-items-start gap-2"
                        >
                            <h3
                                id="import-delete-file"
                                className="text-body font-semibold"
                            >
                                {__('Delete the uploaded file')}
                            </h3>
                            <p>
                                {__(
                                    'The export file holds drafts, private posts and people’s email addresses. When the import is done, delete it. What was imported stays, and so does this report.',
                                )}
                            </p>
                            <Form
                                {...ImportFileController.destroy.form({
                                    file: run.file.id,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                <ConfirmAction
                                    title={__('Delete the uploaded file?')}
                                    description={__(
                                        'What was imported stays, and so does this report. To import the site again, upload its file again.',
                                    )}
                                    confirmLabel={__('Delete the file')}
                                    confirmVariant="destructive"
                                >
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        data-test="import-delete-file"
                                    >
                                        {__('Delete the file…')}
                                    </Button>
                                </ConfirmAction>
                            </Form>
                            {run.file.deletesOn !== null && (
                                <p className="text-small text-ink-2">
                                    {__(
                                        'Teme deletes the uploaded file on :date.',
                                        {
                                            date: dayInZone(
                                                run.file.deletesOn,
                                                siteTimezone,
                                            ),
                                        },
                                    )}
                                </p>
                            )}
                        </section>
                    )}
                </>
            )}

            {finished && latest && run.file === null && !site.forgotten && (
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
        </div>
    );

    if (!framed) {
        return body;
    }

    return (
        <Panel aria-labelledby="import-report-heading">
            <PanelHeader>
                <h2
                    id="import-report-heading"
                    className="text-body font-semibold"
                >
                    {finished ? __('Report') : __('Report so far')}
                </h2>
            </PanelHeader>
            <PanelBody>{body}</PanelBody>
        </Panel>
    );
}

/** One warning or error code as a foldable plain-name group with its count. */
function MessageGroup({
    row,
    number,
    seeItemsHref,
}: {
    row: MessageRow;
    number: (value: number) => string;
    /** The attention download, when the report offers one. */
    seeItemsHref: string | null;
}) {
    const sentence = row.sentence ?? row.words;

    return (
        <li>
            <Collapsible defaultOpen={false}>
                <CollapsibleTrigger
                    className="hover:bg-surface-2 focus-visible:outline-ring group flex w-full items-center gap-3 rounded-md px-3 py-2 text-start focus-visible:outline-2"
                    data-test={`import-message-${row.code}`}
                >
                    <StatusPill
                        tone={row.level === 'error' ? 'danger' : 'warn'}
                        className="shrink-0"
                    >
                        {row.levelLabel}
                    </StatusPill>
                    <span className="text-body min-w-0 flex-1 font-medium">
                        {row.group}
                    </span>
                    <span className="text-ink-3 shrink-0 tabular-nums">
                        {number(row.count)}
                    </span>
                    <ChevronDown
                        aria-hidden="true"
                        className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                    />
                </CollapsibleTrigger>
                <CollapsibleContent className="text-body text-ink-2 grid gap-2 px-3 ps-12 pb-3">
                    <p>{sentence}</p>
                    {row.sentence !== null && row.words !== row.sentence && (
                        <p className="text-small text-ink-3">{row.words}</p>
                    )}
                    {seeItemsHref !== null && (
                        <p>
                            <TextButton asChild>
                                <a
                                    href={seeItemsHref}
                                    download
                                    data-test={`import-see-items-${row.code}`}
                                >
                                    {__n(
                                        'See the :count item|See the :count items',
                                        row.count,
                                        { count: number(row.count) },
                                    )}
                                </a>
                            </TextButton>
                        </p>
                    )}
                </CollapsibleContent>
            </Collapsible>
        </li>
    );
}
