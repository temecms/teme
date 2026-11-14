import { Link, router } from '@inertiajs/react';
import { ListChecks, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import MyJobsController from '@/actions/App/Http/Admin/MyJobsController';
import { HeaderPanel } from '@/components/header/header-panel';
import type { useHeaderActivity } from '@/components/header/use-header-activity';
import { JobProgress } from '@/components/jobs/job-progress';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatInZone } from '@/lib/zoned-time';

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
    const { counts, jobs, jobsFailed, loadJobs } = activity;

    if (counts === null || !counts.hasJobs) {
        return null;
    }

    const toggle = (next: boolean) => {
        setOpen(next);

        if (next) {
            loadJobs();
        }
    };

    const act = (verb: 'retry' | 'cancel', id: string) =>
        router.post(
            MyJobsController[verb].url({ job: id }),
            {},
            { preserveScroll: true, onFinish: loadJobs },
        );

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
                    className="relative max-md:size-11"
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
                            className="bg-foreground text-card absolute top-[3px] right-[2px] h-4 min-w-4 rounded-lg px-1 font-mono text-[10px] leading-4"
                        >
                            {shown(counts.running)}
                        </span>
                    )}
                    {counts.running === 0 && counts.failed > 0 && (
                        <span
                            aria-hidden="true"
                            className="bg-destructive text-destructive-foreground absolute top-[3px] right-[2px] h-4 min-w-4 rounded-lg px-1 font-mono text-[10px] leading-4"
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
            {jobsFailed && (
                <p
                    role="status"
                    className="text-muted-foreground p-3.5 text-[13px]"
                >
                    Your jobs couldn’t be loaded. Try again in a moment.
                </p>
            )}
            {!jobsFailed && jobs === null && (
                <div className="grid gap-2 p-3.5" role="status">
                    <Skeleton className="h-10 motion-safe:animate-pulse" />
                    <Skeleton className="h-10 motion-safe:animate-pulse" />
                </div>
            )}
            {!jobsFailed && jobs !== null && jobs.rows.length === 0 && (
                <div className="p-3.5 text-[13px]">
                    <p className="font-medium">No jobs yet</p>
                    <p className="text-muted-foreground">
                        Long tasks you start show their progress here.
                    </p>
                </div>
            )}
            {!jobsFailed && jobs !== null && jobs.rows.length > 0 && (
                <ul aria-label="Your jobs" className="divide-border divide-y">
                    {jobs.rows.map((row) => (
                        <li
                            key={row.id}
                            data-test="my-job"
                            className="flex items-start justify-between gap-3 px-3.5 py-2.5 text-[13px]"
                        >
                            <div className="grid min-w-0 gap-1">
                                <span className="font-medium">{row.label}</span>
                                <JobProgress job={row} />
                                <span className="text-muted-foreground text-xs">
                                    {row.stateLabel}
                                    {row.message !== null &&
                                        ` · ${row.message}`}
                                </span>
                                <span className="text-muted-foreground text-xs">
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
        </HeaderPanel>
    );
}
