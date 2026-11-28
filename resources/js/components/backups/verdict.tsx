import { Form, Link } from '@inertiajs/react';
import { Check, Loader2, TriangleAlert, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import BackupPlacesController from '@/actions/App/Http/Admin/Tools/Backups/BackupPlacesController';
import BackupSetupController from '@/actions/App/Http/Admin/Tools/Backups/BackupSetupController';
import {
    BACKUP_STATE_ID,
    BackupJobLine,
} from '@/components/backups/back-up-now';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody } from '@/components/ui/panel';
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
              'The database (:database) and :count media file (:media). It added :added and took :took.|The database (:database) and :count media files (:media). It added :added and took :took.',
              newest.mediaFiles,
              {
                  ...sizes,
                  count: newest.mediaFiles.toLocaleString(),
                  media: sizeWords(newest.mediaBytes),
              },
          )
        : __(
              'The database (:database). It added :added and took :took.',
              sizes,
          );
}

/** A moment in words with its first letter raised, to lead a line: "Today at 03:10". */
function leading(words: string): string {
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Each tone's ground, mark and word colour, and the mark's icon. */
const TONES = {
    ok: {
        ground: 'bg-ok-soft',
        mark: 'bg-ok',
        word: 'text-ok-ink',
        Icon: Check,
    },
    warn: {
        ground: 'bg-warn-soft',
        mark: 'bg-warn',
        word: 'text-warn-ink',
        Icon: TriangleAlert,
    },
    danger: {
        ground: 'bg-danger-soft',
        mark: 'bg-danger',
        word: 'text-danger-ink',
        Icon: X,
    },
} as const;

/**
 * The verdict as a block on its tone's ground: a round mark, the word,
 * the sentence as a heading, what follows it, and its actions. The word is
 * written out, so the colour never says it alone (I-2409).
 */
function VerdictBlock({
    tone,
    word,
    title,
    actions,
    children,
}: {
    tone: keyof typeof TONES;
    word: string;
    title: string;
    actions?: ReactNode;
    children?: ReactNode;
}) {
    const { ground, mark, word: wordColour, Icon } = TONES[tone];

    return (
        <Panel
            aria-labelledby="backup-verdict-title"
            data-test="backup-verdict"
            data-tone={tone}
            className={`soft-edge shadow-none ${ground}`}
        >
            <div className="flex flex-col gap-4 p-4 sm:flex-row sm:p-6">
                <span
                    aria-hidden="true"
                    className={`text-surface grid size-12 shrink-0 place-items-center rounded-full ${mark}`}
                >
                    <Icon className="size-6" strokeWidth={2.5} />
                </span>
                <div className="text-body grid min-w-0 flex-1 content-start justify-items-start gap-2">
                    <span
                        className={`text-caption font-semibold uppercase ${wordColour}`}
                    >
                        {word}
                    </span>
                    <h2
                        id="backup-verdict-title"
                        className="text-title font-semibold"
                    >
                        {title}
                    </h2>
                    {children}
                    {actions !== undefined && (
                        <div className="flex flex-wrap gap-2 pt-2">
                            {actions}
                        </div>
                    )}
                </div>
            </div>
        </Panel>
    );
}

/** 1.12e's verdict on its block (I-2409): the word and heading for its level, then its own sentences. */
function VerdictFromLevel({ verdict }: { verdict: VerdictLine }) {
    const more =
        verdict.more.length > 0 ? (
            <ul className="grid list-disc gap-1 ps-5">
                {verdict.more.map((line) => (
                    <li key={line}>{line}</li>
                ))}
            </ul>
        ) : null;

    if (verdict.level === 'green') {
        return (
            <VerdictBlock
                tone="ok"
                word={__('Safe')}
                title={__('Your site is safe.')}
            >
                <p>{verdict.sentence}</p>
                {more}
            </VerdictBlock>
        );
    }

    if (verdict.level === 'red') {
        return (
            <VerdictBlock
                tone="danger"
                word={__('Not safe')}
                title={__('Your site is not safe right now.')}
            >
                <p>{verdict.sentence}</p>
                {more}
            </VerdictBlock>
        );
    }

    return (
        <VerdictBlock
            tone="warn"
            word={__('Needs a look')}
            title={verdict.sentence}
        >
            {more}
        </VerdictBlock>
    );
}

/**
 * Running now, the newest backup and the next, side by side under the
 * verdict, with the hour and what's kept changed in place.
 */
function Facts({ page }: { page: BackupsPage }) {
    const { running, firstBackup, newest, schedule, backupJob } = page;
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

    let runningNow: ReactNode;

    if (jobOpen) {
        runningNow = (
            <>
                <strong className="font-semibold">
                    {__('A backup is running')}
                </strong>
                <BackupJobLine job={backupJob} running={running} />
            </>
        );
    } else if (running !== null) {
        runningNow = (
            <p className="flex items-center gap-2 font-semibold">
                <Loader2
                    aria-hidden="true"
                    className="size-4 motion-safe:animate-spin"
                />
                {running}
            </p>
        );
    } else {
        runningNow = (
            <>
                <strong className="font-semibold">
                    {__('Nothing is running')}
                </strong>
                <span className="text-small text-ink-2">
                    {__('The next one starts by itself.')}
                </span>
            </>
        );
    }

    return (
        <Panel aria-label={__('What is happening')} data-test="backup-facts">
            <PanelBody className="pt-5">
                <dl className="grid gap-5 sm:grid-cols-3">
                    <div className="grid content-start gap-1">
                        <dt className="text-small text-ink-3">
                            {__('Running now')}
                        </dt>
                        <dd className="grid justify-items-start gap-1">
                            {runningNow}
                        </dd>
                    </div>
                    <div className="grid content-start gap-1">
                        <dt className="text-small text-ink-3">
                            {__('Newest backup')}
                        </dt>
                        <dd className="grid justify-items-start gap-1">
                            {newest !== null ? (
                                <>
                                    <strong className="font-semibold">
                                        {leading(newest.when)}
                                    </strong>
                                    <span className="text-small text-ink-2">
                                        {newestLine(newest)}
                                    </span>
                                </>
                            ) : (
                                <>
                                    <strong className="font-semibold">
                                        {__('None yet')}
                                    </strong>
                                    {firstBackup !== null && (
                                        <span className="text-small text-ink-2">
                                            {firstBackup}
                                        </span>
                                    )}
                                </>
                            )}
                        </dd>
                    </div>
                    <div className="grid content-start gap-1">
                        <dt className="text-small text-ink-3">
                            {__('Next backup')}
                        </dt>
                        <dd className="grid justify-items-start gap-1">
                            {schedule === null ? (
                                <>
                                    <strong className="font-semibold">
                                        {__('Paused')}
                                    </strong>
                                    <span className="text-small text-ink-2">
                                        {__(
                                            'Nothing is backed up until you answer above.',
                                        )}
                                    </span>
                                </>
                            ) : (
                                <>
                                    <strong className="font-semibold">
                                        {leading(schedule.nextAt)}
                                    </strong>
                                    <span className="text-small text-ink-2">
                                        {__('In the site’s time zone, :zone.', {
                                            zone: schedule.zone,
                                        })}{' '}
                                        {schedule.kept}
                                    </span>
                                    {!changing && (
                                        <Button
                                            ref={change}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setChanging(true)}
                                            data-test="backup-schedule-change"
                                        >
                                            {__('Change')}
                                            <span className="sr-only">
                                                {' '}
                                                {__(
                                                    'when backups run and what’s kept',
                                                )}
                                            </span>
                                        </Button>
                                    )}
                                </>
                            )}
                        </dd>
                    </div>
                </dl>

                {schedule !== null && changing && (
                    <ScheduleForm
                        schedule={schedule}
                        onClose={() => setChanging(false)}
                    />
                )}
            </PanelBody>
        </Panel>
    );
}

/**
 * The top of Tools › Backups (I-2409): the verdict on its tone's ground,
 * then what runs now, the newest backup and the next one, with the hour
 * and what's kept changed in place.
 */
export function Verdict({ page }: { page: BackupsPage }) {
    const { setup, verdict } = page;

    // Under TEME_BACKUPS=host the host does the backups: a healthy block, with Teme's own as a second copy.
    if (setup.state === 'none' && verdict?.level === 'green') {
        return (
            <VerdictBlock
                tone="ok"
                word={__('Safe')}
                title={verdict.sentence}
                actions={
                    <Button asChild data-test="backup-set-up">
                        <Link href={setupRoute()}>{__('Set up backups')}</Link>
                    </Button>
                }
            >
                {verdict.more.map((line) => (
                    <p key={line}>{line}</p>
                ))}
            </VerdictBlock>
        );
    }

    if (setup.state === 'none') {
        return (
            <VerdictBlock
                tone="danger"
                word={__('Not backed up')}
                title={__('Your site is not backed up.')}
                actions={
                    <Button asChild data-test="backup-set-up">
                        <Link href={setupRoute()}>{__('Set up backups')}</Link>
                    </Button>
                }
            >
                <p>{__('Nothing on this site is backed up yet.')}</p>
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
            </VerdictBlock>
        );
    }

    if (setup.state === 'unfinished') {
        return (
            <VerdictBlock
                tone="danger"
                word={__('Not backed up')}
                title={__('Backups aren’t set up yet.')}
                actions={
                    <>
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
                                    className="text-danger-ink"
                                    data-test="backup-start-again"
                                >
                                    {__('Remove :name and start again', {
                                        name: setup.placeName ?? '',
                                    })}
                                </Button>
                            </ConfirmAction>
                        </Form>
                    </>
                }
            >
                <p>
                    {__(
                        'The recovery key shown for :place wasn’t typed back. Nothing is backed up until it is.',
                        { place: setup.placeName ?? '' },
                    )}
                </p>
            </VerdictBlock>
        );
    }

    if (setup.state === 'paused') {
        const places = names(page.places.map((place) => place.name));

        return (
            <>
                <VerdictBlock
                    tone="warn"
                    word={__('Paused')}
                    title={__('Backups are paused.')}
                    actions={
                        <>
                            <Form action={restored().url} method="post">
                                <input
                                    type="hidden"
                                    name="answer"
                                    value="site"
                                />
                                <ConfirmAction
                                    title={__('Is this server now the site?')}
                                    description={__(
                                        'Backups resume here, at :places. Make sure the old server no longer backs up into them: two sites must never back up into one place.',
                                        { places },
                                    )}
                                    confirmLabel={__(
                                        'This server is now the site',
                                    )}
                                    confirmVariant="default"
                                >
                                    <Button
                                        type="submit"
                                        data-test="restored-site"
                                    >
                                        {__('This server is now the site')}
                                    </Button>
                                </ConfirmAction>
                            </Form>
                            <Form action={restored().url} method="post">
                                <input
                                    type="hidden"
                                    name="answer"
                                    value="copy"
                                />
                                <ConfirmAction
                                    title={__('Is this a copy of the site?')}
                                    description={__(
                                        'Teme removes :places from this site’s backup places. Their backups stay where they are, and you can set backups up for this copy afterwards.',
                                        { places },
                                    )}
                                    confirmLabel={__(
                                        'Remove these backup places',
                                    )}
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
                        </>
                    }
                >
                    <p>
                        {__(
                            'Backups are paused because this site was restored, until you say whether this server is now the site.',
                        )}
                    </p>
                    <p>
                        {__(
                            'The restored site’s places are the old site’s too, and two sites must never back up into one place.',
                        )}
                    </p>
                </VerdictBlock>
                <Facts page={page} />
            </>
        );
    }

    return (
        <>
            <div id={BACKUP_STATE_ID} className="grid">
                {verdict && <VerdictFromLevel verdict={verdict} />}
            </div>
            <Facts page={page} />
        </>
    );
}
