import { Link, router } from '@inertiajs/react';
import { ListChecks, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import MyJobsController from '@/actions/App/Http/Admin/MyJobsController';
import { HeaderPanel } from '@/components/header/header-panel';
import type { useHeaderActivity } from '@/components/header/use-header-activity';
import { JobProgress } from '@/components/jobs/job-progress';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { formatInZone } from '@/lib/zoned-time';

/** The count drawn on the button's corner. */
const COUNT =
    'bg-ink text-page text-caption pointer-events-none absolute end-0 top-0 inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 font-mono';

/** A count as the badge shows it: "99+" from 100. */
const shown = (n: number): string => (n >= 100 ? '99+' : String(n));

/** The button's name, which says the counts in words. */
function nameOf(running: number, failed: number): string {
    const parts = [
        running > 0 ? `${shown(running)} running` : null,
        failed > 0 ? `${shown(failed)} failed` : null,
    ].filter((part) => part !== null);

    return parts.length === 0 ? 'Jobs' : `Jobs, ${parts.join(', ')}`;
}

/**
 * The status line under a job: the state alone when the message only repeats
 * it ("Finished."), else "Failed · reason" with the job's real summary.
 */
function statusLine(stateLabel: string, message: string | null): string {
    if (message === null) {
        return stateLabel;
    }

    const trimmed = message.trim();
    const withoutStops = trimmed.replace(/[.!?]+$/u, '');

    if (
        withoutStops.localeCompare(stateLabel, undefined, {
            sensitivity: 'accent',
        }) === 0
    ) {
        return stateLabel;
    }

    return `${stateLabel} · ${trimmed}`;
}

/**
 * The header's jobs button (I-104): drawn only for someone who has a job, it
 * counts the jobs running, or else those failed, and opens their latest ten
 * with Retry and Cancel.
 */
export function JobsButton({
    activity,
}: {
    activity: ReturnType<typeof useHeaderActivity>;
}) {
    const [open, setOpen] = useState(false);
    const { counts, jobs, loadJobs } = activity;

    if (counts === null || !counts.hasJobs) {
        return null;
    }

    const toggle = (next: boolean) => {
        setOpen(next);

        if (next) {
            loadJobs();
        }
    };

    return (
        <HeaderPanel
            open={open}
            onOpenChange={toggle}
            title="Your jobs"
            testId="jobs-panel"
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    data-test="jobs-button"
                    aria-label={nameOf(counts.running, counts.failed)}
                    className="relative"
                >
                    {counts.running > 0 ? (
                        <LoaderCircle
                            aria-hidden="true"
                            className="motion-safe:animate-spin"
                        />
                    ) : (
                        <ListChecks aria-hidden="true" />
                    )}
                    {counts.running > 0 && (
                        <span aria-hidden="true" className={COUNT}>
                            {shown(counts.running)}
                        </span>
                    )}
                    {counts.running === 0 && counts.failed > 0 && (
                        <span
                            aria-hidden="true"
                            className={cn(COUNT, 'bg-danger text-on-danger')}
                        >
                            {shown(counts.failed)}
                        </span>
                    )}
                </Button>
            }
            footer={
                jobs?.toolsJobsHref ? (
                    <Link
                        href={jobs.toolsJobsHref}
                        className="underline-offset-4 hover:underline"
                    >
                        See everyone’s jobs in Tools › Jobs
                    </Link>
                ) : undefined
            }
        >
            <JobsPanelBody activity={activity} />
        </HeaderPanel>
    );
}

/**
 * The person's latest jobs with Retry and Cancel: the jobs panel's content,
 * also drawn in the small screens' status sheet.
 */
export function JobsPanelBody({
    activity,
}: {
    activity: ReturnType<typeof useHeaderActivity>;
}) {
    const { jobs, jobsFailed, loadJobs } = activity;

    const act = (verb: 'retry' | 'cancel', id: string) =>
        router.post(
            MyJobsController[verb].url({ job: id }),
            {},
            { preserveScroll: true, onFinish: loadJobs },
        );

    return (
        <>
            {jobsFailed && (
                <p role="status" className="text-ink-2 text-small px-4 py-3">
                    Your jobs couldn’t be loaded. Try again in a moment.
                </p>
            )}
            {!jobsFailed && jobs === null && (
                <div className="grid gap-2 px-4 py-3" role="status">
                    <Skeleton className="h-10 motion-safe:animate-pulse" />
                    <Skeleton className="h-10 motion-safe:animate-pulse" />
                </div>
            )}
            {!jobsFailed && jobs !== null && jobs.rows.length === 0 && (
                <div className="text-small px-4 py-3">
                    <p className="font-medium">No jobs yet</p>
                    <p className="text-ink-2">
                        Long tasks you start show their progress here.
                    </p>
                </div>
            )}
            {!jobsFailed && jobs !== null && jobs.rows.length > 0 && (
                <ul aria-label="Your jobs" className="divide-line divide-y">
                    {jobs.rows.map((row) => (
                        <li
                            key={row.id}
                            data-test="my-job"
                            className="text-small flex items-start justify-between gap-3 px-4 py-3"
                        >
                            <div className="grid min-w-0 gap-1">
                                <span className="font-medium">{row.label}</span>
                                <JobProgress job={row} />
                                <span className="text-ink-2 text-caption">
                                    {statusLine(row.stateLabel, row.message)}
                                </span>
                                <span className="text-ink-2 text-caption">
                                    Started{' '}
                                    {formatInZone(
                                        row.startedAt,
                                        jobs.siteTimezone,
                                    )}
                                </span>
                            </div>
                            {row.canRetry && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Retry ${row.label}`}
                                    onClick={() => act('retry', row.id)}
                                >
                                    Retry
                                </Button>
                            )}
                            {row.canCancel && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Cancel ${row.label}`}
                                    onClick={() => act('cancel', row.id)}
                                >
                                    Cancel
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
