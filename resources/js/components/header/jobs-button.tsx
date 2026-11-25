import { Link, router } from '@inertiajs/react';
import { ListChecks, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import MyJobsController from '@/actions/App/Http/Admin/MyJobsController';
import { HeaderPanel } from '@/components/header/header-panel';
import type { useHeaderActivity } from '@/components/header/use-header-activity';
import { JobProgress } from '@/components/jobs/job-progress';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';

/** A count as the badge shows it: "99+" from 100. */
const shown = (n: number): string => (n >= 100 ? '99+' : String(n));

/** The button's name, which says the counts in words. */
function nameOf(running: number, failed: number): string {
    if (running > 0 && failed > 0) {
        return `Jobs, ${shown(running)} running, ${shown(failed)} failed`;
    }

    if (running > 0) {
        return `Jobs, ${shown(running)} running`;
    }

    if (failed > 0) {
        return `Jobs, ${shown(failed)} failed`;
    }

    return __('No jobs running');
}

/**
 * The header's jobs button: always shown; a turning icon and count while
 * jobs run, else idle with "No jobs running" as its name. Opens the latest
 * ten with Retry and Cancel.
 */
export function JobsButton({
    activity,
}: {
    activity: ReturnType<typeof useHeaderActivity>;
}) {
    const [open, setOpen] = useState(false);
    const { counts, jobs, loadJobs } = activity;

    if (counts === null) {
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
                    size="sm"
                    data-test="jobs-button"
                    aria-label={nameOf(counts.running, counts.failed)}
                    className="gap-1"
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
                        <span
                            aria-hidden="true"
                            className="text-small font-semibold tabular-nums"
                        >
                            {shown(counts.running)}
                        </span>
                    )}
                    {counts.running === 0 && counts.failed > 0 && (
                        <span
                            aria-hidden="true"
                            className="text-danger-ink text-small font-semibold tabular-nums"
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
                    <p className="font-medium">{__('No jobs running')}</p>
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
                                    {row.stateLabel}
                                    {row.message !== null &&
                                        ` · ${row.message}`}
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
