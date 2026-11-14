import type { JobRow } from '@/types/jobs';

/**
 * How far a job has got: a native progress bar and its words, "188 of 400 ·
 * 47%", or "188 done" while its total isn't known.
 */
export function JobProgress({
    job,
}: {
    job: Pick<JobRow, 'label' | 'state' | 'total' | 'done' | 'percent'>;
}) {
    const words =
        job.total === null
            ? `${job.done.toLocaleString()} done`
            : `${job.done.toLocaleString()} of ${job.total.toLocaleString()}${job.percent === null ? '' : ` · ${job.percent}%`}`;

    return (
        <div className="grid min-w-[180px] gap-1">
            <progress
                aria-label={`Progress of ${job.label}`}
                max={
                    job.total === null || job.total === 0
                        ? undefined
                        : job.total
                }
                value={job.total === null ? undefined : job.done}
                data-state={job.state}
                className="bg-muted [&::-moz-progress-bar]:bg-foreground [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-foreground data-[state=failed]:[&::-moz-progress-bar]:bg-destructive data-[state=failed]:[&::-webkit-progress-value]:bg-destructive data-[state=finished]:[&::-moz-progress-bar]:bg-success data-[state=finished]:[&::-webkit-progress-value]:bg-success h-1.5 w-full overflow-hidden rounded-full"
            />
            <span className="text-muted-foreground text-xs tabular-nums">
                {words}
            </span>
        </div>
    );
}
