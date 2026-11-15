import { Form } from '@inertiajs/react';
import { DatabaseBackup, Loader2 } from 'lucide-react';
import BackUpNowController from '@/actions/App/Http/Admin/Tools/Backups/BackUpNowController';
import JobsController from '@/actions/App/Http/Admin/Tools/JobsController';
import { JobProgress } from '@/components/jobs/job-progress';
import { Button } from '@/components/ui/button';
import { __ } from '@/lib/i18n';
import type { BackupsPage } from '@/types/backups';
import type { JobRow } from '@/types/jobs';

/** The id of the block the header button points to when it says why it is off. */
export const BACKUP_STATE_ID = 'backup-state';

/** Tools › Backups' header action (I-361): asks for a backup now, and says why it can't while one waits, runs or backups are paused. */
export function BackUpNowButton({
    state,
}: {
    state: BackupsPage['backUpNow'];
}) {
    if (state === 'hidden') {
        return null;
    }

    return (
        <Form {...BackUpNowController.form()}>
            {({ processing }) => (
                <Button
                    type="submit"
                    data-test="backup-now"
                    disabled={state !== 'offered' || processing}
                    aria-describedby={
                        state === 'offered' ? undefined : BACKUP_STATE_ID
                    }
                >
                    <DatabaseBackup aria-hidden="true" />
                    {__('Back up now')}
                </Button>
            )}
        </Form>
    );
}

/** A backup job that waits or runs, in the verdict block: where it stands, its progress and Cancel. */
export function BackupJobLine({
    job,
    running,
}: {
    job: JobRow;
    running: string | null;
}) {
    return (
        <div data-test="backup-job" className="grid justify-items-start gap-2">
            {job.state === 'waiting' ? (
                <p className="font-medium">
                    {__(
                        ':job is waiting to start. It starts within a minute, once nothing else is running.',
                        { job: job.label },
                    )}
                </p>
            ) : (
                <>
                    {running !== null && (
                        <p className="flex items-center gap-2 font-medium">
                            <Loader2
                                aria-hidden="true"
                                className="size-4 motion-safe:animate-spin"
                            />
                            {running}
                        </p>
                    )}
                    {job.message !== null && <p>{job.message}</p>}
                    <JobProgress job={job} />
                </>
            )}

            {job.canCancel && (
                <Form
                    {...JobsController.cancel.form({ job: job.id })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            data-test="backup-job-cancel"
                        >
                            {__('Cancel')}
                            <span className="sr-only"> {job.label}</span>
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}
