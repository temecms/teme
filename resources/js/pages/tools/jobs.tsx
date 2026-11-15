import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect } from 'react';
import JobsController from '@/actions/App/Http/Admin/Tools/JobsController';
import { JobLabel } from '@/components/jobs/job-label';
import { JobProgress } from '@/components/jobs/job-progress';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as jobsIndex } from '@/routes/tools/jobs';
import type { JobRow, JobStateKey, JobsPage } from '@/types/jobs';

const badgeVariants: Record<
    JobStateKey,
    'outline' | 'secondary' | 'success' | 'destructive'
> = {
    waiting: 'outline',
    running: 'secondary',
    finished: 'success',
    failed: 'destructive',
    cancelled: 'outline',
};

/** Who started a job, in the words the Started column ends on. */
function startedBy(by: JobRow['startedBy']): string {
    if (by.kind === 'operator') {
        return 'by the server';
    }

    return by.kind === 'removed'
        ? 'by a removed account'
        : `by ${by.name ?? ''}`;
}

/** Tools › Jobs (I-104): everyone's background work, how far it's got and how it ended. */
export default function Jobs({
    rows,
    newer,
    older,
    failed,
    siteTimezone,
    inboxHref,
}: JobsPage) {
    const { start, stop } = usePoll(
        3000,
        { only: ['rows', 'failed', 'newer', 'older'] },
        { autoStart: false },
    );
    const underWay = rows.some(
        (row) => row.state === 'waiting' || row.state === 'running',
    );

    useEffect(() => {
        if (underWay) {
            start();
        } else {
            stop();
        }
    }, [underWay, start, stop]);

    const columns: Column<JobRow>[] = [
        {
            id: 'job',
            owner: 'core',
            header: 'Job',
            cell: (row) => <JobLabel job={row} />,
        },
        {
            id: 'progress',
            owner: 'core',
            header: 'Progress',
            cell: (row) => <JobProgress job={row} />,
        },
        {
            id: 'state',
            owner: 'core',
            header: 'State',
            cell: (row) => (
                <div className="grid justify-items-start gap-1">
                    <Badge variant={badgeVariants[row.state]}>
                        {row.stateLabel}
                    </Badge>
                    {row.message !== null && (
                        <span className="text-ink-2 text-xs">
                            {row.message}
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'started',
            owner: 'core',
            header: 'Started',
            cell: (row) => (
                <div className="grid gap-0.5 text-[13px]">
                    <span>{formatInZone(row.startedAt, siteTimezone)}</span>
                    <span className="text-ink-2">
                        {startedBy(row.startedBy)}
                    </span>
                </div>
            ),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">Actions</span>,
            cell: (row) => (
                <div className="flex justify-end gap-2">
                    {row.canRetry && (
                        <Form
                            {...JobsController.retry.form({ job: row.id })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Retry ${row.label}`}
                                    disabled={processing}
                                >
                                    Retry
                                </Button>
                            )}
                        </Form>
                    )}
                    {row.canCancel && (
                        <Form
                            {...JobsController.cancel.form({ job: row.id })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Cancel ${row.label}`}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Jobs" />

            <ListPage
                title="Jobs"
                description="Imports, bulk changes and other long tasks. When one you started finishes, you’ll get a message in your inbox, so you can leave this page."
                actions={
                    <>
                        {failed > 0 && (
                            <Form
                                {...JobsController.retryFailed.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        data-test="jobs-retry-failed"
                                        disabled={processing}
                                    >
                                        <RefreshCw aria-hidden="true" />
                                        Retry all failed (
                                        {failed.toLocaleString()})
                                    </Button>
                                )}
                            </Form>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={inboxHref}>Open inbox</Link>
                        </Button>
                    </>
                }
            >
                <DataTable
                    caption="Jobs"
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    empty={
                        <EmptyState
                            kind="nothing-yet"
                            title="No jobs yet"
                            description="Imports and other long tasks show their progress here."
                        />
                    }
                />

                {rows.length > 0 && <Pager newer={newer} older={older} />}
            </ListPage>
        </>
    );
}

Jobs.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Jobs', href: jobsIndex() },
    ],
};
