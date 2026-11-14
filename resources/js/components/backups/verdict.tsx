import { Form, Link } from '@inertiajs/react';
import {
    CircleCheck,
    CircleX,
    Loader2,
    Pause,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import BackupSetupController from '@/actions/App/Http/Admin/Tools/Backups/BackupSetupController';
import {
    BACKUP_STATE_ID,
    BackupJobLine,
} from '@/components/backups/back-up-now';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { names, sizeWords } from '@/lib/backup-words';
import { setup as setupRoute } from '@/routes/tools/backups';
import { restored } from '@/routes/tools/backups';
import type { BackupsPage, NewestBackup, VerdictLine } from '@/types/backups';
import { ScheduleForm } from './schedule-form';

/** The newest backup's line: what it holds, what it added, how long it took. */
function newestLine(newest: NewestBackup): string {
    const media =
        newest.mediaFiles > 0
            ? ` and ${newest.mediaFiles.toLocaleString()} media ${newest.mediaFiles === 1 ? 'file' : 'files'} (${sizeWords(newest.mediaBytes)})`
            : '';

    return `The newest backup holds the database (${sizeWords(newest.databaseBytes)})${media}. It added ${sizeWords(newest.bytesAdded)} and took ${newest.took}.`;
}

/** Each level's icon, its text alternative and the left edge that carries its colour. */
const LEVELS = {
    red: {
        Icon: CircleX,
        alt: 'Needs attention',
        edge: 'border-l-destructive',
        colour: 'text-destructive',
    },
    amber: {
        Icon: TriangleAlert,
        alt: 'Needs a look',
        edge: 'border-l-warning',
        colour: 'text-warning',
    },
    green: {
        Icon: CircleCheck,
        alt: 'All well',
        edge: 'border-l-success',
        colour: 'text-success',
    },
} as const;

/** 1.12e's verdict, its sentence first and the lines under it (I-2409): an icon and words, never colour alone. */
function VerdictSentence({ verdict }: { verdict: VerdictLine }) {
    const { Icon, alt, colour } = LEVELS[verdict.level];

    return (
        <div className="space-y-1">
            <p className="flex items-start gap-2 font-medium">
                <Icon
                    aria-hidden="true"
                    className={`mt-0.5 size-4 shrink-0 ${colour}`}
                />
                <span>
                    <span className="sr-only">{alt}. </span>
                    {verdict.sentence}
                </span>
            </p>
            {verdict.more.length > 0 && (
                <ul className="text-muted-foreground space-y-1 pl-6 text-sm">
                    {verdict.more.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The top of Tools › Backups (I-2409): where setup stands, what runs now,
 * the newest backup and the next one, with the hour and what's kept changed
 * in place beside it.
 */
export function Verdict({ page }: { page: BackupsPage }) {
    const {
        setup,
        running,
        firstBackup,
        newest,
        schedule,
        verdict,
        backupJob,
    } = page;
    const jobOpen =
        backupJob !== null &&
        (backupJob.state === 'waiting' || backupJob.state === 'running');
    const [changing, setChanging] = useState(false);
    const change = useRef<HTMLButtonElement | null>(null);
    const opened = useRef(false);

    useEffect(() => {
        // Cancel and Save put focus back on Change.
        if (opened.current && !changing) {
            change.current?.focus();
        }

        opened.current = changing;
    }, [changing]);

    if (setup.state === 'none') {
        return (
            <section
                aria-labelledby="backup-set-up-heading"
                data-test="backup-verdict"
                className="border-border space-y-3 rounded-md border p-4"
            >
                <h2
                    id="backup-set-up-heading"
                    className="text-base font-medium"
                >
                    Set up backups
                </h2>
                <p className="font-medium">
                    Nothing on this site is backed up yet.
                </p>
                <p className="text-sm">
                    Teme backs up the database and every media file each night,
                    to a folder on this server, S3-compatible storage or an SFTP
                    server, and checks every backup by reading it back.
                </p>
                <p className="text-sm">
                    Backups are locked with a recovery key that Teme shows you
                    once. With it and a copy of the backups, this site can be
                    brought back on any server. Without it, nobody can open
                    them, Teme included.
                </p>
                <p className="text-sm">
                    It takes a minute. Have a pen and paper or a printer ready.
                </p>
                <Button asChild data-test="backup-set-up">
                    <Link href={setupRoute()}>Set up backups</Link>
                </Button>
            </section>
        );
    }

    if (setup.state === 'unfinished') {
        return (
            <section
                aria-label="Where setup stands"
                data-test="backup-verdict"
                className="border-destructive space-y-3 rounded-md border border-l-4 p-4"
            >
                <p className="font-medium">
                    <span className="sr-only">Needs attention. </span>
                    Backups aren’t set up yet: the recovery key shown for{' '}
                    {setup.placeName} wasn’t typed back.
                </p>
                <p className="text-muted-foreground text-sm">
                    Nothing is backed up until it is.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Form {...BackupSetupController.store.form()}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="backup-again"
                            >
                                Show a new recovery key
                            </Button>
                        )}
                    </Form>
                    <Form
                        {...BackupPlacesController.destroy.form({
                            place: setup.placeId ?? '',
                        })}
                    >
                        <ConfirmAction
                            title={`Remove ${setup.placeName} and start again?`}
                            description={`Teme forgets ${setup.placeName} and the key it showed. Anything Teme made there stays: choose an empty folder or bucket next time.`}
                            confirmLabel={`Remove ${setup.placeName}`}
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                data-test="backup-start-again"
                            >
                                Remove {setup.placeName} and start again
                            </Button>
                        </ConfirmAction>
                    </Form>
                </div>
            </section>
        );
    }

    if (setup.state === 'paused') {
        const places = names(page.places.map((place) => place.name));

        return (
            <section
                aria-label="Where setup stands"
                data-test="backup-verdict"
                className="border-destructive space-y-3 rounded-md border border-l-4 p-4"
            >
                <p className="flex items-start gap-2 font-medium">
                    <Pause aria-hidden="true" className="mt-0.5 size-4" />
                    <span>
                        <span className="sr-only">Needs attention. </span>
                        Backups are paused because this site was restored, until
                        you say whether this server is now the site.
                    </span>
                </p>
                <p className="text-muted-foreground text-sm">
                    The restored site’s places are the old site’s too, and two
                    sites must never back up into one place.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Form action={restored().url} method="post">
                        <input type="hidden" name="answer" value="site" />
                        <ConfirmAction
                            title="Is this server now the site?"
                            description={`Backups resume here, at ${places}. Make sure the old server no longer backs up into them: two sites must never back up into one place.`}
                            confirmLabel="This server is now the site"
                            confirmVariant="default"
                        >
                            <Button type="submit" data-test="restored-site">
                                This server is now the site
                            </Button>
                        </ConfirmAction>
                    </Form>
                    <Form action={restored().url} method="post">
                        <input type="hidden" name="answer" value="copy" />
                        <ConfirmAction
                            title="Is this a copy of the site?"
                            description={`Teme removes ${places} from this site’s backup places. Their backups stay where they are, and you can set backups up for this copy afterwards.`}
                            confirmLabel="Remove these backup places"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                data-test="restored-copy"
                            >
                                This is a copy: remove these backup places
                            </Button>
                        </ConfirmAction>
                    </Form>
                </div>
            </section>
        );
    }

    return (
        <section
            id={BACKUP_STATE_ID}
            aria-label="The state of the backups"
            data-test="backup-verdict"
            className={`border-border space-y-3 rounded-md border p-4 ${verdict ? `border-l-4 ${LEVELS[verdict.level].edge}` : ''}`}
        >
            {verdict && <VerdictSentence verdict={verdict} />}

            {jobOpen ? (
                <BackupJobLine job={backupJob} running={running} />
            ) : (
                running !== null && (
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <Loader2
                            aria-hidden="true"
                            className="size-4 motion-safe:animate-spin"
                        />
                        {running}
                    </p>
                )
            )}

            {firstBackup !== null && <p className="text-sm">{firstBackup}</p>}

            {newest !== null && <p className="text-sm">{newestLine(newest)}</p>}

            {schedule !== null &&
                (changing ? (
                    <ScheduleForm
                        schedule={schedule}
                        onClose={() => setChanging(false)}
                    />
                ) : (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <p className="text-sm">
                            {schedule.next} {schedule.kept}
                        </p>
                        <Button
                            ref={change}
                            type="button"
                            variant="outline"
                            onClick={() => setChanging(true)}
                            data-test="backup-schedule-change"
                        >
                            Change
                            <span className="sr-only">
                                {' '}
                                when backups run and what’s kept
                            </span>
                        </Button>
                    </div>
                ))}
        </section>
    );
}
