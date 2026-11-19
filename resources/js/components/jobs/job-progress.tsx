import { Progress } from '@/components/ui/progress';
import { __ } from '@/lib/i18n';
import type { JobRow, JobStateKey } from '@/types/jobs';

const TONES: Record<JobStateKey, 'accent' | 'ok' | 'danger' | 'quiet'> = {
    waiting: 'accent',
    running: 'accent',
    finished: 'ok',
    failed: 'danger',
    cancelled: 'quiet',
};

/** How far a job has got, in words: "64% done", "Stopped at 88%", "Done". */
function words(job: Pick<JobRow, 'state' | 'done' | 'percent'>): string {
    if (job.state === 'finished') {
        return __('Done');
    }

    if (job.percent === null) {
        return __(':count done', { count: job.done.toLocaleString() });
    }

    return job.state === 'failed' || job.state === 'cancelled'
        ? __('Stopped at :percent%', { percent: job.percent })
        : __(':percent% done', { percent: job.percent });
}

/**
 * How far a job has got: an accent bar while it runs, red where it failed,
 * green once done, and its words; a waiting job has not started.
 */
export function JobProgress({
    job,
}: {
    job: Pick<JobRow, 'label' | 'state' | 'total' | 'done' | 'percent'>;
}) {
    if (job.state === 'waiting' && job.done === 0) {
        return (
            <span className="text-small text-ink-2">{__('Not started')}</span>
        );
    }

    const share =
        job.state === 'finished'
            ? 100
            : job.percent === null
              ? null
              : job.percent;

    return (
        <div className="grid w-40 max-w-full gap-1">
            {share !== null && (
                <Progress
                    value={share}
                    tone={TONES[job.state]}
                    data-state={job.state}
                    label={__('Progress of :label', { label: job.label })}
                />
            )}
            <span className="text-small text-ink-2 tabular-nums">
                {words(job)}
            </span>
        </div>
    );
}
