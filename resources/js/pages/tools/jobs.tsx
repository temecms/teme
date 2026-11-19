import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePoll,
} from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect } from 'react';
import JobsController from '@/actions/App/Http/Admin/Tools/JobsController';
import { JobLabel } from '@/components/jobs/job-label';
import { JobProgress } from '@/components/jobs/job-progress';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { Pager } from '@/components/list/pager';
import { InPageNav } from '@/components/patterns/in-page-nav';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as jobsIndex } from '@/routes/tools/jobs';
import type { JobStateKey, JobsPage, JobsScreenRow } from '@/types/jobs';

const TONES: Record<JobStateKey, 'info' | 'neutral' | 'ok' | 'danger'> = {
    waiting: 'neutral',
    running: 'info',
    finished: 'ok',
    failed: 'danger',
    cancelled: 'neutral',
};

/** The state filter's choices, in v10's order. */
const STATES: { value: JobStateKey; label: () => string }[] = [
    { value: 'running', label: () => __('Running') },
    { value: 'waiting', label: () => __('Waiting') },
    { value: 'finished', label: () => __('Finished') },
    { value: 'failed', label: () => __('Failed') },
    { value: 'cancelled', label: () => __('Cancelled') },
];

/** Who started a job, in the words the Started column ends on. */
function startedBy(row: JobsScreenRow): string {
    const by = row.startedBy;

    if (by.kind === 'operator') {
        return __('by the server');
    }

    if (by.kind === 'removed') {
        return __('by a removed account');
    }

    return row.mine ? __('by you') : __('by :name', { name: by.name ?? '' });
}

/** Tools › Jobs (I-104): background work, how far it has got and how it ended. */
export default function Jobs({
    rows,
    newer,
    older,
    whose,
    state,
    counts,
    shown,
    failed,
    siteTimezone,
    inboxHref,
}: JobsPage) {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Jobs'), href: jobsIndex() },
        ],
    });

    const { start, stop } = usePoll(
        3000,
        { only: ['rows', 'failed', 'newer', 'older', 'counts', 'shown'] },
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

    const address = (next: { whose?: string; state?: string | null }) => {
        const query: Record<string, string> = {};
        const nextWhose = next.whose ?? whose;
        const nextState = next.state === undefined ? state : next.state;

        if (nextWhose === 'everyone') {
            query.whose = nextWhose;
        }

        if (nextState) {
            query.state = nextState;
        }

        return jobsIndex({ query }).url;
    };

    const columns: Column<JobsScreenRow>[] = [
        {
            id: 'job',
            owner: 'core',
            header: __('Job'),
            cell: (row) => <JobLabel job={row} />,
        },
        {
            id: 'progress',
            owner: 'core',
            header: __('Progress'),
            cell: (row) => <JobProgress job={row} />,
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => (
                <div className="grid justify-items-start gap-1">
                    <StatusPill tone={TONES[row.state]}>
                        {row.stateLabel}
                    </StatusPill>
                    {row.message !== null && (
                        <span className="text-small text-ink-3">
                            {row.message}
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'started',
            owner: 'core',
            header: __('Started'),
            cell: (row) => (
                <div className="text-small grid whitespace-nowrap">
                    <span>{formatInZone(row.startedAt, siteTimezone)}</span>
                    <span className="text-ink-3">{startedBy(row)}</span>
                </div>
            ),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
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
                                    aria-label={__('Retry :label', {
                                        label: row.label,
                                    })}
                                    disabled={processing}
                                >
                                    <RefreshCw aria-hidden="true" />
                                    {__('Retry')}
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
                                    aria-label={__('Cancel :label', {
                                        label: row.label,
                                    })}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
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
            <Head title={__('Jobs')} />

            <ListPage
                title={__('Jobs')}
                description={__(
                    'Imports, bulk changes and other long tasks. When one you started finishes, you’ll get a message in your inbox, so you can leave this page.',
                )}
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
                                        {__('Retry all failed (:count)', {
                                            count: failed.toLocaleString(),
                                        })}
                                    </Button>
                                )}
                            </Form>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={inboxHref}>{__('Open inbox')}</Link>
                        </Button>
                    </>
                }
            >
                <div className="min-w-0">
                    <InPageNav
                        label={__('Whose jobs')}
                        items={[
                            {
                                label: __('Mine'),
                                count: counts.mine,
                                current: whose === 'mine',
                                href: address({ whose: 'mine' }),
                            },
                            {
                                label: __('Everyone’s'),
                                count: counts.everyone,
                                current: whose === 'everyone',
                                href: address({ whose: 'everyone' }),
                            },
                        ]}
                    />
                </div>

                <div
                    data-test="list-filters"
                    className="flex flex-wrap items-center gap-2"
                >
                    <SelectField
                        aria-label={__('State')}
                        emptyLabel={__('All states')}
                        className="w-48"
                        value={state ?? ''}
                        options={STATES.map((choice) => ({
                            value: choice.value,
                            label: choice.label(),
                        }))}
                        onValueChange={(next) =>
                            router.visit(address({ state: next || null }), {
                                preserveScroll: true,
                            })
                        }
                    />
                    {state !== null && (
                        <Button variant="ghost" asChild>
                            <Link href={address({ state: null })}>
                                {__('Clear filters')}
                            </Link>
                        </Button>
                    )}
                </div>

                <DataTable
                    caption={__('Jobs')}
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    empty={
                        state !== null ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={__('No jobs match')}
                                description={__(
                                    'Try another state, or everyone’s jobs.',
                                )}
                                clearHref={address({ state: null })}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                icon={RefreshCw}
                                title={__('No jobs yet')}
                                description={__(
                                    'Imports and other long tasks show their progress here.',
                                )}
                            />
                        )
                    }
                />

                {rows.length > 0 && (
                    <div className="grid gap-3">
                        <p className="text-small text-ink-3">
                            {__n(
                                ':count job, newest first. Finished jobs are kept for 90 days.|:count jobs, newest first. Finished jobs are kept for 90 days.',
                                shown,
                                { count: shown.toLocaleString() },
                            )}
                        </p>
                        {(newer !== null || older !== null) && (
                            <Pager
                                newer={newer}
                                older={older}
                                labels={{
                                    newer: __('Newer'),
                                    older: __('Older'),
                                }}
                            />
                        )}
                    </div>
                )}
            </ListPage>
        </>
    );
}
