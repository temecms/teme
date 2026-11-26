import { Head, Link, router, setLayoutProps, usePoll } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    CircleCheck,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { DashboardPage } from '@/components/patterns/dashboard-page';
import {
    Code,
    duration,
    UpdateButton,
    UpdateProgress,
    WithCode,
} from '@/components/tools/update-progress';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Notice } from '@/components/ui/notice';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { SoonTag } from '@/components/ui/soon-tag';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __, __n } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as backupsIndex } from '@/routes/tools/backups';
import { index as updatesIndex } from '@/routes/tools/updates';
import type { UpdateRelease, UpdatesPage } from '@/types';

/** How many lines of a release's notes show before "Show all". */
const NOTE_LINES = 8;

/** The settings and commands the page names, typed as they are. */
const CODES = {
    channel: 'TEME_UPDATES_CHANNEL',
    check: 'TEME_UPDATES_CHECK',
    deploy: 'php artisan teme:deploy',
    checkNow: 'php artisan teme:updates:check',
    image: 'TEME_IMAGE',
    updaterImage: 'TEME_UPDATER_IMAGE',
    env: '.env',
    pull: 'docker compose pull',
    up: 'docker compose up --detach',
};

/** Definition lists' two columns from the small breakpoint up. */
const FACTS: CSSProperties = {
    '--facts-cols': 'max-content 1fr',
} as CSSProperties;

/** How each deploy outcome reads, and its tone, looked up as it is drawn. */
function outcome(name: string): {
    label: string;
    tone: 'ok' | 'warn' | 'danger';
} {
    const outcomes: Record<
        string,
        { label: string; tone: 'ok' | 'warn' | 'danger' }
    > = {
        deployed: { label: __('Deployed'), tone: 'ok' },
        failed: { label: __('Failed'), tone: 'danger' },
        refused: { label: __('Refused'), tone: 'danger' },
        'backup-failed': { label: __('Backup failed'), tone: 'danger' },
        'migration-failed': { label: __('Migration failed'), tone: 'danger' },
        unfinished: { label: __('Unfinished'), tone: 'warn' },
        busy: { label: __('Busy'), tone: 'warn' },
    };

    return outcomes[name] ?? { label: name, tone: 'warn' };
}

/** What an update does, step by step, with each step's service: what the updater's record names. */
function updateSteps(): { code: string; title: string; line: string }[] {
    return [
        {
            code: 'deploy',
            title: __('Back up and migrate'),
            line: __(
                'Takes a backup when the database must change, then migrates it. Teme stays on the old release until this works.',
            ),
        },
        {
            code: 'web',
            title: __('Start the web service'),
            line: __(
                'Starts the new web service and waits until it answers with the new version.',
            ),
        },
        {
            code: 'worker',
            title: __('Start the worker'),
            line: __(
                'Starts the worker that does background jobs on the new release.',
            ),
        },
        {
            code: 'worker-long',
            title: __('Start the long-jobs worker'),
            line: __('The worker for imports and other long tasks.'),
        },
        {
            code: 'scheduler',
            title: __('Start the scheduler'),
            line: __(
                'The part that publishes scheduled posts and runs the nightly work.',
            ),
        },
        {
            code: 'check',
            title: __('Check everything'),
            line: __(
                'A last look that every part is healthy and the site answers.',
            ),
        },
        {
            code: 'updater',
            title: __('Update the updater'),
            line: __(
                'The updater switches itself to the new release, so the next update starts from it.',
            ),
        },
    ];
}

/**
 * The settings updating needs. Whether each is set is said only for those
 * this server reads; the images and the platform are the updater's, set on
 * the platform, so the site can't see them (null).
 */
function neededSettings(
    checkOn: boolean,
    channelSet: boolean,
): { name: string; line: string; set: boolean | null }[] {
    return [
        {
            name: 'TEME_UPDATES_CHECK',
            line: __(
                'Asks GitHub once an hour which releases exist. Sends nothing about this site.',
            ),
            set: checkOn,
        },
        {
            name: 'TEME_UPDATES_CHANNEL',
            line: __(
                'Which releases this site gets: releases only, or pre-releases too.',
            ),
            set: channelSet,
        },
        {
            name: 'TEME_IMAGE',
            line: __('The Teme image this site runs, as an exact tag.'),
            set: null,
        },
        {
            name: 'TEME_UPDATER_IMAGE',
            line: __(
                'The updater service that carries out an update asked for on this page.',
            ),
            set: null,
        },
        {
            name: 'TEME_UPDATER_PLATFORM',
            line: __(
                'Where the site runs, so the updater knows how to restart it (Dokploy).',
            ),
            set: null,
        },
    ];
}

/** One of the page's sections: a panel under its heading. */
function Section({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <Panel aria-labelledby={id}>
            <PanelHeader>
                <h2 id={id} className="text-body font-semibold">
                    {title}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body">{children}</PanelBody>
        </Panel>
    );
}

function Marks({ release }: { release: UpdateRelease }) {
    return (
        <span className="flex flex-wrap gap-2">
            {release.prerelease && (
                <StatusPill tone="neutral">{__('Pre-release')}</StatusPill>
            )}
            {release.security && (
                <StatusPill tone="warn">{__('Security fixes')}</StatusPill>
            )}
            {release.maintenance && (
                <StatusPill tone="warn">
                    {__('Needs maintenance mode')}
                </StatusPill>
            )}
        </span>
    );
}

function Notes({ release }: { release: UpdateRelease }) {
    const [all, setAll] = useState(false);
    const lines = release.notes.reduce(
        (sum, section) =>
            sum +
            (section.heading === null ? 0 : 1) +
            section.paragraphs.length +
            section.items.length,
        0,
    );
    let left = all ? Infinity : NOTE_LINES;

    return (
        <div className="grid justify-items-start gap-2">
            {release.notes.map((section, at) => {
                if (left <= 0) {
                    return null;
                }

                const heading = section.heading !== null && left-- > 0;
                const paragraphs = section.paragraphs.slice(
                    0,
                    Math.max(left, 0),
                );
                left -= paragraphs.length;
                const items = section.items.slice(0, Math.max(left, 0));
                left -= items.length;

                return (
                    <div key={at} className="grid gap-1">
                        {heading && (
                            <h3 className="font-semibold">{section.heading}</h3>
                        )}
                        {paragraphs.map((text, i) => (
                            <p key={i}>{text}</p>
                        ))}
                        {items.length > 0 && (
                            <ul className="grid list-disc gap-1 ps-5">
                                {items.map((text, i) => (
                                    <li key={i}>{text}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                );
            })}
            {lines > NOTE_LINES && (
                <TextButton aria-expanded={all} onClick={() => setAll(!all)}>
                    {all
                        ? __('Show less')
                        : __('Show all :count lines', { count: lines })}
                </TextButton>
            )}
        </div>
    );
}

/** Tools › Updates (I-3329): which Teme this site runs, the releases after it, the check and the deploys. */
export default function Updates({
    running,
    newer,
    newest,
    check,
    deploys,
    update,
    rollback,
    updaterSeenAt,
    canUpdate,
    siteTimezone,
}: UpdatesPage) {
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Updates'), href: updatesIndex() },
        ],
    });
    const when = (iso: string) => formatInZone(iso, siteTimezone);
    const open = update?.status === 'requested' || update?.status === 'running';
    // The update last seen open: only a poll's answer compares against it.
    const watched = useRef<string | null>(open ? update.id : null);
    const { start, stop } = usePoll(
        5000,
        {
            only: [
                'update',
                'running',
                'deploys',
                'newer',
                'rollback',
                'updaterSeenAt',
            ],
            // While the site restarts the proxy answers with its own 404 or the connection
            // fails: keep the last block and poll again, with no error box (issue 328).
            onHttpException: () => false,
            onNetworkError: () => false,
            onSuccess: (visit) => {
                const seen = (visit.props as { update?: UpdatesPage['update'] })
                    .update;

                if (seen && ['requested', 'running'].includes(seen.status)) {
                    watched.current = seen.id;

                    return;
                }

                if (watched.current !== null) {
                    watched.current = null;
                    // The release running, what is newer and what can be asked follow the update.
                    router.reload();
                }
            },
        },
        // Full speed in a background tab too: the person watches the steps from another window.
        { autoStart: false, keepAlive: true },
    );

    useEffect(() => {
        if (open) {
            watched.current = update.id;
            start();
        } else {
            stop();
        }
    }, [open, update?.id, start, stop]); // eslint-disable-line react-hooks/exhaustive-deps
    const lastDeployed = deploys.find((run) => run.outcome === 'deployed');
    const top = newer[0] ?? null;
    const older = newer.slice(1);
    const securityBefore =
        top !== null && !top.security
            ? newer.find((release) => release.security)
            : undefined;

    const notRelease = __(
        'This build isn’t a release, so Teme doesn’t compare it with the releases.',
    );
    const noDeploy = (
        <WithCode
            words={__(
                'No deploy recorded yet. Each :deploy is recorded from this release on.',
            )}
            codes={CODES}
        />
    );

    return (
        <>
            <Head title={__('Updates')} />

            <DashboardPage
                title={__('Updates')}
                description={__(
                    'Which Teme this site runs, and the releases after it.',
                )}
                actions={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            disabled
                            data-test="updates-check-now"
                        >
                            <RefreshCw aria-hidden="true" />
                            {__('Check for updates')}
                            <SoonTag />
                        </Button>
                        {top !== null && (
                            <UpdateButton
                                version={top.version}
                                canUpdate={canUpdate}
                                open={open}
                            />
                        )}
                    </>
                }
            >
                <div className="grid gap-4">
                    {update !== null && (
                        <UpdateProgress
                            update={update}
                            rollback={rollback}
                            updaterSeenAt={updaterSeenAt}
                            deploys={deploys}
                            when={when}
                        />
                    )}

                    <div className="grid items-start gap-4 lg:grid-cols-2">
                        <Section id="updates-site" title={__('This site')}>
                            <dl
                                className="grid gap-x-6 gap-y-2 sm:grid-cols-(--facts-cols)"
                                style={FACTS}
                            >
                                <dt className="text-small text-ink-2">
                                    {__('Version')}
                                </dt>
                                <dd>
                                    <strong className="font-semibold">
                                        {__('Teme :version', {
                                            version: running.version,
                                        })}
                                    </strong>
                                    {running.tagged && (
                                        <>
                                            {' '}
                                            <StatusPill tone="ok">
                                                {__('A release')}
                                            </StatusPill>
                                        </>
                                    )}
                                    {running.revision !== null && (
                                        <>
                                            {` · ${__('Build')} `}
                                            <Code>{running.revision}</Code>
                                        </>
                                    )}
                                    {!running.tagged && (
                                        <span className="text-ink-2 block">
                                            {notRelease}
                                        </span>
                                    )}
                                </dd>

                                <dt className="text-small text-ink-2">
                                    {__('Gets')}
                                </dt>
                                <dd>
                                    {running.channel === 'prerelease'
                                        ? __('Every release, pre-releases too')
                                        : __('Releases only')}
                                    <span className="text-small text-ink-3 block">
                                        <WithCode
                                            words={
                                                running.channelSet
                                                    ? __('Set by :channel.')
                                                    : __(
                                                          'Follows the version this site runs. To choose, set :channel.',
                                                      )
                                            }
                                            codes={CODES}
                                        />
                                    </span>
                                </dd>

                                <dt className="text-small text-ink-2">
                                    {__('Last deploy')}
                                </dt>
                                <dd>
                                    {lastDeployed === undefined
                                        ? noDeploy
                                        : __(
                                              'Deployed Teme :version on :date.',
                                              {
                                                  version: lastDeployed.version,
                                                  date: when(
                                                      lastDeployed.finishedAt,
                                                  ),
                                              },
                                          )}
                                </dd>
                            </dl>
                        </Section>
                        <Section
                            id="updates-check"
                            title={__('Checking for releases')}
                        >
                            {!check.enabled ? (
                                <p>
                                    <WithCode
                                        words={__(
                                            'Update checks are off on this server (:check).',
                                        )}
                                        codes={CODES}
                                    />
                                </p>
                            ) : (
                                <>
                                    <p>
                                        {__(
                                            'Once an hour Teme asks GitHub for its list of releases. It sends nothing about this site.',
                                        )}
                                    </p>
                                    {check.checkedAt === null ? (
                                        <p className="text-ink-2">
                                            <WithCode
                                                words={__(
                                                    'Not checked yet. It runs within the hour, or now with :checkNow.',
                                                )}
                                                codes={CODES}
                                            />
                                        </p>
                                    ) : (
                                        <>
                                            {check.error !== null && (
                                                <Notice tone="warn">
                                                    <span className="sr-only">
                                                        {__('Problem:')}{' '}
                                                    </span>
                                                    {check.error}
                                                </Notice>
                                            )}
                                            <dl
                                                className="grid gap-x-6 gap-y-2 sm:grid-cols-(--facts-cols)"
                                                style={FACTS}
                                            >
                                                <dt className="text-small text-ink-2">
                                                    {__('Last checked')}
                                                </dt>
                                                <dd>{when(check.checkedAt)}</dd>
                                                {check.succeededAt !== null && (
                                                    <>
                                                        <dt className="text-small text-ink-2">
                                                            {__(
                                                                'Last read the list',
                                                            )}
                                                        </dt>
                                                        <dd>
                                                            {when(
                                                                check.succeededAt,
                                                            )}
                                                        </dd>
                                                    </>
                                                )}
                                            </dl>
                                        </>
                                    )}
                                </>
                            )}
                        </Section>
                    </div>

                    <Section id="updates-available" title={__('Available')}>
                        {!running.tagged ? (
                            <>
                                <p>{notRelease}</p>
                                {newest !== null && (
                                    <p className="text-ink-2">
                                        {__(
                                            'The newest release is Teme :version, published :date.',
                                            {
                                                version: newest.version,
                                                date: when(newest.publishedAt),
                                            },
                                        )}
                                    </p>
                                )}
                            </>
                        ) : newest === null ? (
                            <>
                                <p>{__('No release has been found yet.')}</p>
                                {!check.enabled && (
                                    <p className="text-ink-2">
                                        {__(
                                            'Update checks are off, so this list isn’t kept up to date.',
                                        )}
                                    </p>
                                )}
                            </>
                        ) : top === null ? (
                            <>
                                <p className="flex items-center gap-2">
                                    <CircleCheck
                                        aria-hidden="true"
                                        className="text-ok-ink size-4 shrink-0"
                                    />
                                    <span>
                                        <span className="sr-only">
                                            {__('All well.')}{' '}
                                        </span>
                                        {__(
                                            'This site runs the newest release it gets.',
                                        )}
                                    </span>
                                </p>
                                <p className="text-ink-2">
                                    {__('Teme :version, published :date.', {
                                        version: newest.version,
                                        date: when(newest.publishedAt),
                                    })}
                                </p>
                            </>
                        ) : (
                            <>
                                {check.error !== null &&
                                    check.succeededAt !== null && (
                                        <p className="flex items-start gap-2">
                                            <span className="flex h-5 items-center">
                                                <TriangleAlert
                                                    aria-hidden="true"
                                                    className="text-warn-ink size-4 shrink-0"
                                                />
                                            </span>
                                            <span>
                                                {__(
                                                    'This list is from :date, the last time Teme could read it.',
                                                    {
                                                        date: when(
                                                            check.succeededAt,
                                                        ),
                                                    },
                                                )}
                                            </span>
                                        </p>
                                    )}
                                <p className="font-semibold">
                                    {newer.length === 1
                                        ? __('Teme :version is available', {
                                              version: top.version,
                                          })
                                        : __n(
                                              ':count release after yours · Teme :version is the newest|:count releases after yours · Teme :version is the newest',
                                              newer.length,
                                              { version: top.version },
                                          )}
                                </p>
                                <p className="flex flex-wrap items-center gap-2">
                                    <Marks release={top} />
                                    <span className="text-ink-2">
                                        {__('Published :date', {
                                            date: when(top.publishedAt),
                                        })}
                                    </span>
                                </p>
                                {securityBefore !== undefined && (
                                    <Notice tone="warn">
                                        {__(
                                            ':before carries security fixes. Updating to :version brings them too.',
                                            {
                                                before: securityBefore.version,
                                                version: top.version,
                                            },
                                        )}
                                    </Notice>
                                )}
                                {top.maintenance && (
                                    <Notice tone="info">
                                        {__(
                                            'This release changes the database in a way the one before can’t run beside. The site must be in maintenance mode while it deploys: see the release’s notes.',
                                        )}
                                    </Notice>
                                )}
                                <Notes release={top} />
                                {top.url !== null && (
                                    <p>
                                        <a
                                            href={top.url}
                                            className="underline"
                                            rel="noreferrer"
                                        >
                                            {__('Read it on GitHub')}
                                        </a>
                                    </p>
                                )}
                                {older.length > 0 && (
                                    <div className="grid gap-2">
                                        <p className="text-ink-2">
                                            {__('Also after yours')}
                                        </p>
                                        {older.map((release) => (
                                            <Collapsible
                                                key={release.version}
                                                className="border-line rounded-md border"
                                            >
                                                <CollapsibleTrigger className="group focus-visible:ring-ring flex min-h-10 w-full items-center gap-3 rounded-md px-3 text-start outline-none focus-visible:ring-2">
                                                    <span className="flex-1">
                                                        {__(
                                                            'Teme :version · Published :date',
                                                            {
                                                                version:
                                                                    release.version,
                                                                date: when(
                                                                    release.publishedAt,
                                                                ),
                                                            },
                                                        )}
                                                    </span>
                                                    <ChevronDown
                                                        aria-hidden="true"
                                                        className="size-4 shrink-0 group-data-[state=open]:rotate-180"
                                                    />
                                                </CollapsibleTrigger>
                                                <CollapsibleContent className="grid gap-3 px-3 pb-3">
                                                    <Marks release={release} />
                                                    <Notes release={release} />
                                                </CollapsibleContent>
                                            </Collapsible>
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </Section>

                    <div className="grid items-start gap-4 lg:grid-cols-2">
                        <Section
                            id="updates-does"
                            title={__('What Update does')}
                        >
                            <p className="text-small text-ink-2">
                                {__(
                                    'An update is a new image. Teme never changes code on a running server. These are the steps, in order:',
                                )}
                            </p>
                            <ol
                                aria-label={__('Steps of the update')}
                                className="grid gap-3"
                            >
                                {updateSteps().map((step, at) => (
                                    <li
                                        key={step.code}
                                        className="flex items-start gap-3"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="bg-surface-3 text-caption text-ink-2 grid size-6 shrink-0 place-items-center rounded-full font-semibold"
                                        >
                                            {at + 1}
                                        </span>
                                        <span className="grid min-w-0 gap-1">
                                            <span className="font-semibold">
                                                {step.title}{' '}
                                                <Code>{step.code}</Code>
                                            </span>
                                            <span className="text-small text-ink-2">
                                                {step.line}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </Section>
                        <Section
                            id="updates-settings"
                            title={__('Settings this needs')}
                        >
                            <ul className="grid gap-3">
                                {neededSettings(
                                    check.enabled,
                                    running.channelSet,
                                ).map((setting) => (
                                    <li
                                        key={setting.name}
                                        className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1"
                                    >
                                        <span className="grid min-w-0 flex-1 basis-60 gap-1">
                                            <Code>{setting.name}</Code>
                                            <span className="text-small text-ink-2">
                                                {setting.line}
                                            </span>
                                        </span>
                                        {setting.set !== null && (
                                            <StatusPill
                                                tone={
                                                    setting.set ? 'ok' : 'warn'
                                                }
                                            >
                                                {setting.set
                                                    ? __('Set')
                                                    : __('Not set')}
                                            </StatusPill>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <p className="text-small text-ink-2">
                                {__(
                                    'The host sets these in the environment. Teme shows only whether each is set, never its value.',
                                )}
                            </p>
                        </Section>
                    </div>

                    <section
                        aria-labelledby="updates-deploys"
                        className="text-body grid gap-3"
                    >
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2
                                id="updates-deploys"
                                className="text-lead font-semibold"
                            >
                                {__('Deploys')}
                            </h2>
                            <span className="text-small text-ink-2">
                                {__('The last ten, newest first.')}
                            </span>
                        </div>
                        {deploys.length === 0 ? (
                            <p className="text-ink-2">{noDeploy}</p>
                        ) : (
                            <ul className="grid gap-3">
                                {deploys.map((run, at) => {
                                    const { label, tone } = outcome(
                                        run.outcome,
                                    );
                                    const sentence =
                                        run.outcome === 'deployed'
                                            ? __(
                                                  'Updated to Teme :version. :ran',
                                                  {
                                                      version: run.version,
                                                      ran:
                                                          run.migrations === 0
                                                              ? __(
                                                                    'No migrations to run.',
                                                                )
                                                              : run.backupRun !==
                                                                  null
                                                                ? __n(
                                                                      ':count migration ran, after a backup.|:count migrations ran, after a backup.',
                                                                      run.migrations,
                                                                  )
                                                                : __n(
                                                                      ':count migration ran.|:count migrations ran.',
                                                                      run.migrations,
                                                                  ),
                                                  },
                                              )
                                            : __('Teme :version. :sentence', {
                                                  version: run.version,
                                                  sentence: run.sentence,
                                              });

                                    return (
                                        <li
                                            key={at}
                                            className="grid justify-items-start gap-1"
                                        >
                                            <StatusPill tone={tone}>
                                                {label}
                                            </StatusPill>
                                            <span>{sentence}</span>
                                            <span className="text-ink-2">
                                                {__(':date · took :took', {
                                                    date: when(run.finishedAt),
                                                    took: duration(
                                                        run.startedAt,
                                                        run.finishedAt,
                                                    ),
                                                })}
                                                {run.backupRun !== null && (
                                                    <>
                                                        {' · '}
                                                        <Link
                                                            href={backupsIndex()}
                                                            className="underline"
                                                        >
                                                            {__(
                                                                'See the backup',
                                                            )}
                                                        </Link>
                                                    </>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>

                    <Collapsible
                        className="bg-surface shadow-raised rounded-lg"
                        data-test="updates-how"
                    >
                        <CollapsibleTrigger className="text-body focus-visible:outline-ring group flex w-full items-center gap-2 rounded-lg px-5 py-4 text-start font-semibold focus-visible:outline-2">
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-90"
                            />
                            {__('How to update by hand')}
                        </CollapsibleTrigger>
                        <CollapsibleContent className="text-body grid gap-3 px-5 pb-5">
                            <p>{__('To update by hand instead:')}</p>
                            <ul className="grid list-disc gap-1 ps-5">
                                <li>
                                    <strong className="font-medium">
                                        {__('On Dokploy:')}
                                    </strong>{' '}
                                    <WithCode
                                        words={__(
                                            'set :image and :updaterImage to the new tag and Deploy.',
                                        )}
                                        codes={CODES}
                                    />
                                </li>
                                <li>
                                    <strong className="font-medium">
                                        {__('With Docker Compose:')}
                                    </strong>{' '}
                                    <WithCode
                                        words={__(
                                            'change :image in :env to the new tag, then run :pull and :up.',
                                        )}
                                        codes={CODES}
                                    />
                                </li>
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </DashboardPage>
        </>
    );
}
