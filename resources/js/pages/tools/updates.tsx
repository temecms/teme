import { Head, Link, router, usePoll } from '@inertiajs/react';
import { CircleCheck, CircleX, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PageHeader } from '@/components/list/page-header';
import {
    duration,
    UpdateButton,
    UpdateProgress,
} from '@/components/tools/update-progress';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatInZone } from '@/lib/zoned-time';
import { index } from '@/routes/tools';
import { index as backupsIndex } from '@/routes/tools/backups';
import { index as updatesIndex } from '@/routes/tools/updates';
import type { UpdateRelease, UpdatesPage } from '@/types';

/** How many lines of a release's notes show before "Show all". */
const NOTE_LINES = 8;

/** How each deploy outcome reads, and how serious it is. */
const OUTCOMES: Record<
    string,
    { label: string; level: 'ok' | 'warn' | 'bad' }
> = {
    deployed: { label: 'Deployed', level: 'ok' },
    failed: { label: 'Failed', level: 'bad' },
    refused: { label: 'Refused', level: 'warn' },
    'backup-failed': { label: 'Backup failed', level: 'bad' },
    'migration-failed': { label: 'Migration failed', level: 'bad' },
    unfinished: { label: 'Unfinished', level: 'warn' },
    busy: { label: 'Busy', level: 'warn' },
};

function Panel({
    id,
    title,
    warn = false,
    children,
}: {
    id: string;
    title: string;
    warn?: boolean;
    children: React.ReactNode;
}) {
    return (
        <section
            aria-labelledby={id}
            className={`rounded-md border p-4 ${warn ? 'border-warning' : 'border-border'}`}
        >
            <h2 id={id} className="text-base font-medium">
                {title}
            </h2>
            <div className="mt-3 space-y-3 text-[13px]">{children}</div>
        </section>
    );
}

function Code({ children }: { children: React.ReactNode }) {
    return <code className="bg-muted rounded px-1 py-0.5">{children}</code>;
}

function Marks({ release }: { release: UpdateRelease }) {
    return (
        <span className="flex flex-wrap gap-2">
            {release.prerelease && (
                <Badge variant="secondary">Pre-release</Badge>
            )}
            {release.security && (
                <Badge variant="outline">
                    <TriangleAlert aria-hidden="true" />
                    Security fixes
                </Badge>
            )}
            {release.maintenance && (
                <Badge variant="outline">Needs maintenance mode</Badge>
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
        <div className="space-y-2">
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
                    <div key={at} className="space-y-1">
                        {heading && (
                            <h3 className="font-medium">{section.heading}</h3>
                        )}
                        {paragraphs.map((text, i) => (
                            <p key={i}>{text}</p>
                        ))}
                        {items.length > 0 && (
                            <ul className="list-disc space-y-0.5 pl-5">
                                {items.map((text, i) => (
                                    <li key={i}>{text}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                );
            })}
            {lines > NOTE_LINES && (
                <Button
                    type="button"
                    variant="link"
                    className="h-auto p-0 text-[13px]"
                    aria-expanded={all}
                    onClick={() => setAll(!all)}
                >
                    {all ? 'Show less' : `Show all ${lines} lines`}
                </Button>
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
    const security = newer.some((release) => release.security);
    const top = newer[0] ?? null;
    const older = newer.slice(1);
    const securityBefore =
        top !== null && !top.security
            ? newer.find((release) => release.security)
            : undefined;

    return (
        <>
            <Head title="Updates" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader
                    title="Updates"
                    description="Which Teme this site runs, and the releases after it."
                    action={
                        top !== null && (
                            <UpdateButton
                                version={top.version}
                                canUpdate={canUpdate}
                                open={open}
                            />
                        )
                    }
                />

                {update !== null && (
                    <UpdateProgress
                        update={update}
                        rollback={rollback}
                        updaterSeenAt={updaterSeenAt}
                        deploys={deploys}
                        when={when}
                    />
                )}

                <Panel id="updates-site" title="This site">
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2">
                        <dt className="text-muted-foreground">Version</dt>
                        <dd>
                            <strong className="font-medium">
                                Teme {running.version}
                            </strong>
                            {running.revision !== null && (
                                <>
                                    {' · Build '}
                                    <Code>{running.revision}</Code>
                                </>
                            )}
                            {!running.tagged && (
                                <span className="text-muted-foreground block">
                                    This build isn’t a release, so Teme doesn’t
                                    compare it with the releases.
                                </span>
                            )}
                        </dd>

                        <dt className="text-muted-foreground">Channel</dt>
                        <dd>
                            {running.channel === 'prerelease'
                                ? 'Gets: every release, pre-releases too'
                                : 'Gets: releases only'}
                            <span className="text-muted-foreground block">
                                {running.channelSet ? (
                                    <>
                                        Set by <Code>TEME_UPDATES_CHANNEL</Code>
                                        .
                                    </>
                                ) : (
                                    <>
                                        Follows the version this site runs. To
                                        choose, set{' '}
                                        <Code>TEME_UPDATES_CHANNEL</Code>.
                                    </>
                                )}
                            </span>
                        </dd>

                        <dt className="text-muted-foreground">Last deploy</dt>
                        <dd>
                            {lastDeployed === undefined ? (
                                <>
                                    No deploy recorded yet. Each{' '}
                                    <Code>php artisan teme:deploy</Code> is
                                    recorded from this release on.
                                </>
                            ) : (
                                `Deployed Teme ${lastDeployed.version} on ${when(lastDeployed.finishedAt)}.`
                            )}
                        </dd>
                    </dl>
                </Panel>

                <Panel id="updates-available" title="Available" warn={security}>
                    {!running.tagged ? (
                        <>
                            <p>
                                This build isn’t a release, so Teme doesn’t
                                compare it with the releases.
                            </p>
                            {newest !== null && (
                                <p className="text-muted-foreground">
                                    The newest release is Teme {newest.version},
                                    published {when(newest.publishedAt)}.
                                </p>
                            )}
                        </>
                    ) : newest === null ? (
                        <>
                            <p>No release has been found yet.</p>
                            {!check.enabled && (
                                <p className="text-muted-foreground">
                                    Update checks are off, so this list isn’t
                                    kept up to date.
                                </p>
                            )}
                        </>
                    ) : top === null ? (
                        <>
                            <p className="flex items-center gap-2">
                                <CircleCheck
                                    aria-hidden="true"
                                    className="text-success size-4 shrink-0"
                                />
                                <span>
                                    <span className="sr-only">All well. </span>
                                    This site runs the newest release it gets.
                                </span>
                            </p>
                            <p className="text-muted-foreground">
                                Teme {newest.version}, published{' '}
                                {when(newest.publishedAt)}.
                            </p>
                        </>
                    ) : (
                        <>
                            {check.error !== null &&
                                check.succeededAt !== null && (
                                    <p className="flex items-start gap-2">
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="text-warning mt-0.5 size-4 shrink-0"
                                        />
                                        <span>
                                            This list is from{' '}
                                            {when(check.succeededAt)}, the last
                                            time Teme could read it.
                                        </span>
                                    </p>
                                )}
                            <p className="flex items-center gap-2 font-medium">
                                {newer.length === 1
                                    ? `Teme ${top.version} is available`
                                    : `${newer.length} releases after yours · Teme ${top.version} is the newest`}
                            </p>
                            <p className="flex flex-wrap items-center gap-2">
                                <Marks release={top} />
                                <span className="text-muted-foreground">
                                    Published {when(top.publishedAt)}
                                </span>
                            </p>
                            {securityBefore !== undefined && (
                                <p className="flex items-start gap-2">
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    <span>
                                        {securityBefore.version} carries
                                        security fixes. Updating to{' '}
                                        {top.version} brings them too.
                                    </span>
                                </p>
                            )}
                            {top.maintenance && (
                                <p role="note">
                                    This release changes the database in a way
                                    the one before can’t run beside. The site
                                    must be in maintenance mode while it
                                    deploys: see the release’s notes.
                                </p>
                            )}
                            <Notes release={top} />
                            {top.url !== null && (
                                <p>
                                    <a
                                        href={top.url}
                                        className="underline"
                                        rel="noreferrer"
                                    >
                                        Read it on GitHub
                                    </a>
                                </p>
                            )}
                            {older.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-muted-foreground">
                                        Also after yours
                                    </p>
                                    {older.map((release) => (
                                        <details
                                            key={release.version}
                                            className="border-border rounded-md border p-3"
                                        >
                                            <summary className="cursor-pointer">
                                                Teme {release.version} ·
                                                Published{' '}
                                                {when(release.publishedAt)}
                                            </summary>
                                            <div className="mt-3 space-y-3">
                                                <Marks release={release} />
                                                <Notes release={release} />
                                            </div>
                                        </details>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </Panel>

                <Panel
                    id="updates-check"
                    title="Checking for releases"
                    warn={check.error !== null}
                >
                    {!check.enabled ? (
                        <p>
                            Update checks are off on this server (
                            <Code>TEME_UPDATES_CHECK</Code>).
                        </p>
                    ) : (
                        <>
                            <p>
                                Once an hour Teme asks GitHub for its list of
                                releases. It sends nothing about this site.
                            </p>
                            {check.checkedAt === null ? (
                                <p className="text-muted-foreground">
                                    Not checked yet. It runs within the hour, or
                                    now with{' '}
                                    <Code>php artisan teme:updates:check</Code>.
                                </p>
                            ) : (
                                <>
                                    {check.error !== null && (
                                        <p role="note">
                                            <span className="sr-only">
                                                Problem:{' '}
                                            </span>
                                            {check.error}
                                        </p>
                                    )}
                                    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2">
                                        <dt className="text-muted-foreground">
                                            Last checked
                                        </dt>
                                        <dd>{when(check.checkedAt)}</dd>
                                        {check.succeededAt !== null && (
                                            <>
                                                <dt className="text-muted-foreground">
                                                    Last read the list
                                                </dt>
                                                <dd>
                                                    {when(check.succeededAt)}
                                                </dd>
                                            </>
                                        )}
                                    </dl>
                                </>
                            )}
                        </>
                    )}
                </Panel>

                <Panel id="updates-how" title="How to update">
                    <p>To update by hand instead:</p>
                    <ul className="list-disc space-y-1 pl-5">
                        <li>
                            <strong className="font-medium">On Dokploy:</strong>{' '}
                            set <Code>TEME_IMAGE</Code> and{' '}
                            <Code>TEME_UPDATER_IMAGE</Code> to the new tag and
                            Deploy.
                        </li>
                        <li>
                            <strong className="font-medium">
                                With Docker Compose:
                            </strong>{' '}
                            change <Code>TEME_IMAGE</Code> in <Code>.env</Code>{' '}
                            to the new tag, then run{' '}
                            <Code>docker compose pull</Code> and{' '}
                            <Code>docker compose up --detach</Code>.
                        </li>
                    </ul>
                </Panel>

                <Panel id="updates-deploys" title="Deploys">
                    <p className="text-muted-foreground">
                        The last ten, newest first.
                    </p>
                    {deploys.length === 0 ? (
                        <p className="text-muted-foreground">
                            No deploy recorded yet. Each{' '}
                            <Code>php artisan teme:deploy</Code> is recorded
                            from this release on.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {deploys.map((run, at) => {
                                const outcome = OUTCOMES[run.outcome] ?? {
                                    label: run.outcome,
                                    level: 'warn' as const,
                                };
                                const Icon =
                                    outcome.level === 'ok'
                                        ? CircleCheck
                                        : outcome.level === 'bad'
                                          ? CircleX
                                          : TriangleAlert;
                                const color = {
                                    ok: 'text-success',
                                    warn: 'text-warning',
                                    bad: 'text-destructive',
                                }[outcome.level];
                                const ran = `${run.migrations} ${run.migrations === 1 ? 'migration' : 'migrations'} ran`;
                                const sentence =
                                    run.outcome === 'deployed'
                                        ? `Updated to Teme ${run.version}. ${
                                              run.migrations === 0
                                                  ? 'No migrations to run.'
                                                  : `${ran}${run.backupRun !== null ? ', after a backup' : ''}.`
                                          }`
                                        : `Teme ${run.version}. ${run.sentence}`;

                                return (
                                    <li key={at} className="space-y-0.5">
                                        <span
                                            className={`${color} flex items-center gap-1.5 font-medium`}
                                        >
                                            <Icon
                                                aria-hidden="true"
                                                className="size-4 shrink-0"
                                            />
                                            {outcome.label}
                                        </span>
                                        <span className="block">
                                            {sentence}
                                        </span>
                                        <span className="text-muted-foreground block">
                                            {when(run.finishedAt)} · took{' '}
                                            {duration(
                                                run.startedAt,
                                                run.finishedAt,
                                            )}
                                            {run.backupRun !== null && (
                                                <>
                                                    {' · '}
                                                    <Link
                                                        href={backupsIndex()}
                                                        className="underline"
                                                    >
                                                        See the backup
                                                    </Link>
                                                </>
                                            )}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}

Updates.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Updates', href: updatesIndex() },
    ],
};
