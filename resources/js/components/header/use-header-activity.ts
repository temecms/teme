import { useHttp, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import MyJobsController from '@/actions/App/Http/Admin/MyJobsController';
import type { HeaderCounts, JobStateKey, MyJobsAnswer } from '@/types/jobs';

/** Tools › Jobs' pace (I-104). */
export const POLL_MS = 3000;

/** The inbox's own titles, said once to screen readers when a watched job ends. */
const ENDED: Partial<Record<JobStateKey, string>> = {
    finished: 'Finished',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

/**
 * The header's counts and the person's latest jobs: the server's counts on
 * every visit, then, while a job of theirs runs and the tab is visible, an
 * answer every three seconds, which also lights the bell when a job ends.
 */
export function useHeaderActivity(): {
    counts: HeaderCounts | null;
    jobs: MyJobsAnswer | null;
    jobsFailed: boolean;
    loadJobs: () => void;
    announcement: string;
} {
    const { headerCounts } = usePage().props;
    const { submit } = useHttp();
    const [counts, setCounts] = useState<HeaderCounts | null>(headerCounts);
    const [jobs, setJobs] = useState<MyJobsAnswer | null>(null);
    const [jobsFailed, setJobsFailed] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    // Each job's state at the last answer, so an ending is said once.
    const seen = useRef(new Map<string, JobStateKey>());
    const running = useRef(headerCounts?.running ?? 0);
    // One request at a time: a poll never cancels the panel's own load.
    const busy = useRef(false);

    // Every visit brings the server's counts.
    useEffect(() => {
        setCounts(headerCounts);
        running.current = headerCounts?.running ?? 0;
    }, [headerCounts]);

    const loadJobs = useCallback(() => {
        if (busy.current) {
            return;
        }

        busy.current = true;
        void (submit(MyJobsController.index()) as Promise<MyJobsAnswer>)
            .then((answer) => {
                const ended = answer.rows.filter((row) => {
                    const before = seen.current.get(row.id);

                    return (
                        (before === 'waiting' || before === 'running') &&
                        ENDED[row.state] !== undefined
                    );
                });

                seen.current = new Map(
                    answer.rows.map((row) => [row.id, row.state]),
                );

                if (answer.running < running.current) {
                    window.dispatchEvent(new Event('teme:jobs-ended'));
                }

                running.current = answer.running;
                setJobs(answer);
                setJobsFailed(false);
                setCounts({
                    running: answer.running,
                    failed: answer.failed,
                    hasJobs: answer.hasJobs,
                    unread: answer.unread,
                });

                if (ended.length > 0) {
                    setAnnouncement(
                        ended
                            .map((row) => `${ENDED[row.state]}: ${row.label}.`)
                            .join(' '),
                    );
                }
            })
            .catch(() => setJobsFailed(true))
            .finally(() => {
                busy.current = false;
            });
    }, [submit]);

    const isRunning = (counts?.running ?? 0) > 0;

    // Only while something runs, and never while the tab is hidden.
    useEffect(() => {
        if (!isRunning) {
            return;
        }

        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                loadJobs();
            }
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [isRunning, loadJobs]);

    return { counts, jobs, jobsFailed, loadJobs, announcement };
}
