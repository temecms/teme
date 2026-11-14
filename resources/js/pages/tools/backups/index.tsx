import { Form, Head, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import BackupSetupController from '@/actions/App/Http/Admin/Tools/Backups/BackupSetupController';
import { PageHeader } from '@/components/list/page-header';
import { BackUpNowButton } from '@/components/backups/back-up-now';
import { ChecksList } from '@/components/backups/checks-list';
import { KeysList } from '@/components/backups/keys-list';
import { PlacesList } from '@/components/backups/places-list';
import { TypedBack } from '@/components/backups/typed-back';
import { Verdict } from '@/components/backups/verdict';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/tools';
import { index as backups } from '@/routes/tools/backups';
import type { BackupsPage } from '@/types/backups';
import type { JobRow } from '@/types/jobs';

/** How long an open page waits for the first backup before it stays still until reloaded: a stopped scheduler would otherwise keep it asking for ever. */
const FIRST_BACKUP_WAIT_MS = 10 * 60 * 1000;

/** The positions, as the pending note asks for them. */
function PendingKey({ page }: { page: BackupsPage }) {
    const pending = page.pending;

    if (pending === null) {
        return null;
    }

    const [a, b] = pending.positions;

    return (
        <section
            aria-label="A recovery key waiting to be finished"
            data-test="backup-pending"
            className="border-border space-y-3 rounded-md border p-4"
        >
            <p className="text-sm">
                If you wrote {pending.label} down, type groups {a} and {b} to
                finish. If you never saw it, show a new key instead.
            </p>
            <TypedBack
                positions={pending.positions}
                challengeId={pending.challengeId}
                extra={
                    pending.purpose === 'set-up' ? (
                        <Form {...BackupSetupController.store.form()}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Show a new key instead
                                </Button>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground self-center text-sm">
                            Or add the key again from Recovery keys.
                        </p>
                    )
                }
            />
        </section>
    );
}

/** Tools › Backups (I-364): is the site safe, where backups are kept, what was checked, and the keys. */
export default function BackupsIndex(page: BackupsPage) {
    const setUp = page.setup.state !== 'none';
    const open =
        page.backupJob !== null &&
        (page.backupJob.state === 'waiting' ||
            page.backupJob.state === 'running');
    const [waitOver, setWaitOver] = useState(false);
    // Just after setup no job exists until the scheduler makes the first, so the page asks while the server says one is on its way.
    const waitingForFirst = page.firstBackupOnItsWay && !waitOver;
    // The job last seen open: only a poll's answer compares against it.
    const watched = useRef<string | null>(open ? page.backupJob!.id : null);
    const { start, stop } = usePoll(
        3000,
        {
            only: [
                'backupJob',
                'running',
                'backUpNow',
                'verdict',
                'firstBackup',
                'firstBackupOnItsWay',
                'newest',
            ],
            onSuccess: (visit) => {
                const job = (visit.props as { backupJob?: JobRow | null })
                    .backupJob;
                const seen = watched.current;

                if (
                    job &&
                    (job.state === 'waiting' || job.state === 'running')
                ) {
                    watched.current = job.id;

                    return;
                }

                if (seen === null) {
                    return;
                }

                watched.current = null;
                // The verdict, the newest backup and the checks follow the job.
                router.reload();

                if (job && job.id === seen) {
                    if (job.state === 'finished') {
                        toast.success(job.message ?? `${job.label} finished`);
                    } else if (job.state === 'failed') {
                        toast.error(
                            `${job.label} failed: ${job.message ?? ''}`,
                        );
                    } else if (job.state === 'cancelled') {
                        toast(job.message ?? `Cancelled ${job.label}`);
                    }
                }
            },
        },
        { autoStart: false },
    );

    useEffect(() => {
        const timer = setTimeout(() => setWaitOver(true), FIRST_BACKUP_WAIT_MS);

        return () => clearTimeout(timer);
    }, []);

    useEffect(() => {
        // A full visit only notes the open job, and toasts nothing.
        if (open) {
            watched.current = page.backupJob!.id;
        }

        if (open || waitingForFirst) {
            start();
        } else {
            stop();
        }
    }, [open, waitingForFirst, page.backupJob?.id, start, stop]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <>
            <Head title="Backups" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title="Backups"
                    description="Encrypted copies of the database and the media, kept where you choose, and checked by reading them back."
                    action={<BackUpNowButton state={page.backUpNow} />}
                />

                <Verdict page={page} />

                {setUp && (
                    <>
                        <PendingKey page={page} />

                        {page.places.length > 0 && (
                            <PlacesList
                                places={page.places}
                                offServerAdvice={page.offServerAdvice}
                                paused={page.setup.state === 'paused'}
                            />
                        )}

                        <ChecksList checks={page.checks} />

                        {page.keys.length > 0 && (
                            <KeysList
                                keys={page.keys}
                                paused={page.setup.state === 'paused'}
                            />
                        )}

                        <section
                            aria-labelledby="backup-restoring"
                            className="space-y-2"
                        >
                            <h2
                                id="backup-restoring"
                                className="text-base font-medium"
                            >
                                Restoring
                            </h2>
                            <p className="text-sm">
                                Restoring is done at the server:{' '}
                                <code className="font-mono">
                                    php artisan teme:backup:restore
                                </code>{' '}
                                checks everything first, backs the site up as it
                                is, then restores. Restoring from this screen
                                comes in a later version.
                            </p>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

BackupsIndex.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
    ],
};
