import { Form, Link } from '@inertiajs/react';
import { CircleCheck, CircleX, Loader2, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import BackupSetupController from '@/actions/App/Http/Admin/Tools/Backups/BackupSetupController';
import {
    BACKUP_STATE_ID,
    BackupJobLine,
} from '@/components/backups/back-up-now';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { __, __n } from '@/lib/i18n';
import { names, sizeWords } from '@/lib/backup-words';
import { setup as setupRoute } from '@/routes/tools/backups';
import { restored } from '@/routes/tools/backups';
import type { BackupsPage, NewestBackup, VerdictLine } from '@/types/backups';
import { ScheduleForm } from './schedule-form';

/** The newest backup's line: what it holds, what it added, how long it took. */
function newestLine(newest: NewestBackup): string {
    const sizes = {
        database: sizeWords(newest.databaseBytes),
        added: sizeWords(newest.bytesAdded),
        took: newest.took,
    };

    return newest.mediaFiles > 0
        ? __n(
              'The newest backup holds the database (:database) and :count media file (:media). It added :added and took :took.|The newest backup holds the database (:database) and :count media files (:media). It added :added and took :took.',
              newest.mediaFiles,
              {
                  ...sizes,
                  count: newest.mediaFiles.toLocaleString(),
                  media: sizeWords(newest.mediaBytes),
              },
          )
        : __(
              'The newest backup holds the database (:database). It added :added and took :took.',
              sizes,
          );
}

/** Each level's icon and its colour; the words are looked up as they are drawn. */
const LEVELS = {
    red: { Icon: CircleX, colour: 'text-danger-ink' },
    amber: { Icon: TriangleAlert, colour: 'text-warn-ink' },
    green: { Icon: CircleCheck, colour: 'text-ok-ink' },
} as const;

/** Each level's text alternative, said before its sentence. */
function levelWords(level: keyof typeof LEVELS): string {
    return {
        red: __('Needs attention'),
        amber: __('Needs a look'),
        green: __('All well'),
    }[level];
}

/** 1.12e's verdict, its sentence first and the lines under it (I-2409): an icon and words, never colour alone. */
function VerdictSentence({ verdict }: { verdict: VerdictLine }) {
    const { Icon, colour } = LEVELS[verdict.level];

    return (
        <div className="grid gap-1">
            <p className="flex items-start gap-2 font-semibold">
                <span className="flex h-lh shrink-0 items-center">
                    <Icon aria-hidden="true" className={`size-4 ${colour}`} />
                </span>
                <span>
                    <span className="sr-only">
                        {levelWords(verdict.level)}.{' '}
                    </span>
                    {verdict.sentence}
                </span>
            </p>
            {verdict.more.length > 0 && (
                <ul className="text-ink-2 grid gap-1 ps-6">
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
            <Panel
                aria-labelledby="backup-set-up-heading"
                data-test="backup-verdict"
            >
                <PanelHeader>
                    <h2
                        id="backup-set-up-heading"
                        className="text-lead font-semibold"
                    >
                        {__('Set up backups')}
                    </h2>
                </PanelHeader>
                <PanelBody className="text-body justify-items-start">
                    <p className="font-medium">
                        {__('Nothing on this site is backed up yet.')}
                    </p>
                    <p>
                        {__(
                            'Teme backs up the database and every media file each night, to a folder on this server, S3-compatible storage or an SFTP server, and checks every backup by reading it back.',
                        )}
                    </p>
                    <p>
                        {__(
                            'Backups are locked with a recovery key that Teme shows you once. With it and a copy of the backups, this site can be brought back on any server. Without it, nobody can open them, Teme included.',
                        )}
                    </p>
                    <p>
                        {__(
                            'It takes a minute. Have a pen and paper or a printer ready.',
                        )}
                    </p>
                    <Button asChild data-test="backup-set-up">
                        <Link href={setupRoute()}>{__('Set up backups')}</Link>
                    </Button>
                </PanelBody>
            </Panel>
        );
    }

    if (setup.state === 'unfinished') {
        return (
            <Panel
                aria-label={__('Where setup stands')}
                data-test="backup-verdict"
            >
                <PanelBody className="text-body pt-5">
                    <Notice tone="danger">
                        <p className="font-semibold">
                            <span className="sr-only">
                                {__('Needs attention.')}{' '}
                            </span>
                            {__(
                                'Backups aren’t set up yet: the recovery key shown for :place wasn’t typed back.',
                                { place: setup.placeName ?? '' },
                            )}
                        </p>

                        <p>{__('Nothing is backed up until it is.')}</p>
                    </Notice>
                    <div className="flex flex-wrap gap-2">
                        <Form {...BackupSetupController.store.form()}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="backup-again"
                                >
                                    {__('Show a new recovery key')}
                                </Button>
                            )}
                        </Form>
                        <Form
                            {...BackupPlacesController.destroy.form({
                                place: setup.placeId ?? '',
                            })}
                        >
                            <ConfirmAction
                                title={__('Remove :name and start again?', {
                                    name: setup.placeName ?? '',
                                })}
                                description={__(
                                    'Teme forgets :name and the key it showed. Anything Teme made there stays: choose an empty folder or bucket next time.',
                                    { name: setup.placeName ?? '' },
                                )}
                                confirmLabel={__('Remove :name', {
                                    name: setup.placeName ?? '',
                                })}
                            >
                                <Button
                                    type="submit"
                                    variant="outline"
                                    data-test="backup-start-again"
                                >
                                    {__('Remove :name and start again', {
                                        name: setup.placeName ?? '',
                                    })}
                                </Button>
                            </ConfirmAction>
                        </Form>
                    </div>
                </PanelBody>
            </Panel>
        );
    }

    if (setup.state === 'paused') {
        const places = names(page.places.map((place) => place.name));

        return (
            <Panel
                aria-label={__('Where setup stands')}
                data-test="backup-verdict"
            >
                <PanelBody className="text-body pt-5">
                    <Notice tone="danger">
                        <p className="font-semibold">
                            <span className="sr-only">
                                {__('Needs attention.')}{' '}
                            </span>
                            {__(
                                'Backups are paused because this site was restored, until you say whether this server is now the site.',
                            )}
                        </p>

                        <p>
                            {__(
                                'The restored site’s places are the old site’s too, and two sites must never back up into one place.',
                            )}
                        </p>
                    </Notice>
                    <div className="flex flex-wrap gap-2">
                        <Form action={restored().url} method="post">
                            <input type="hidden" name="answer" value="site" />
                            <ConfirmAction
                                title={__('Is this server now the site?')}
                                description={__(
                                    'Backups resume here, at :places. Make sure the old server no longer backs up into them: two sites must never back up into one place.',
                                    { places },
                                )}
                                confirmLabel={__('This server is now the site')}
                                confirmVariant="default"
                            >
                                <Button type="submit" data-test="restored-site">
                                    {__('This server is now the site')}
                                </Button>
                            </ConfirmAction>
                        </Form>
                        <Form action={restored().url} method="post">
                            <input type="hidden" name="answer" value="copy" />
                            <ConfirmAction
                                title={__('Is this a copy of the site?')}
                                description={__(
                                    'Teme removes :places from this site’s backup places. Their backups stay where they are, and you can set backups up for this copy afterwards.',
                                    { places },
                                )}
                                confirmLabel={__('Remove these backup places')}
                            >
                                <Button
                                    type="submit"
                                    variant="outline"
                                    data-test="restored-copy"
                                >
                                    {__(
                                        'This is a copy: remove these backup places',
                                    )}
                                </Button>
                            </ConfirmAction>
                        </Form>
                    </div>
                </PanelBody>
            </Panel>
        );
    }

    return (
        <Panel
            id={BACKUP_STATE_ID}
            aria-label={__('The state of the backups')}
            data-test="backup-verdict"
        >
            <PanelBody className="text-body justify-items-start pt-5">
                {verdict && <VerdictSentence verdict={verdict} />}

                {jobOpen ? (
                    <BackupJobLine job={backupJob} running={running} />
                ) : (
                    running !== null && (
                        <p className="flex items-center gap-2 font-medium">
                            <Loader2
                                aria-hidden="true"
                                className="size-4 motion-safe:animate-spin"
                            />
                            {running}
                        </p>
                    )
                )}

                {firstBackup !== null && <p>{firstBackup}</p>}

                {newest !== null && <p>{newestLine(newest)}</p>}

                {schedule !== null &&
                    (changing ? (
                        <ScheduleForm
                            schedule={schedule}
                            onClose={() => setChanging(false)}
                        />
                    ) : (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <p>
                                {schedule.next} {schedule.kept}
                            </p>
                            <Button
                                ref={change}
                                type="button"
                                variant="outline"
                                onClick={() => setChanging(true)}
                                data-test="backup-schedule-change"
                            >
                                {__('Change')}
                                <span className="sr-only">
                                    {' '}
                                    {__('when backups run and what’s kept')}
                                </span>
                            </Button>
                        </div>
                    ))}
            </PanelBody>
        </Panel>
    );
}
