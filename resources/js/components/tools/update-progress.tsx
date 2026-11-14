import { Form, router } from '@inertiajs/react';
import { Circle, CircleCheck, CircleX, Loader2, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import CancelUpdateController from '@/actions/App/Http/Admin/Tools/Updates/CancelUpdateController';
import RequestUpdateController from '@/actions/App/Http/Admin/Tools/Updates/RequestUpdateController';
import { Button } from '@/components/ui/button';
import type { TemeUpdate, TemeUpdateStep, UpdatesPage } from '@/types';

/** The seven steps of an update, in the order the updater takes them. */
const STEPS = [
    'deploy',
    'web',
    'worker',
    'worker-long',
    'scheduler',
    'check',
    'updater',
] as const;

/** Minutes without a look from the updater before the requested block says so (Q3a). */
const UPDATER_QUIET_MINUTES = 2;

/** How each state of a step reads to a screen reader. */
const STATE_WORDS: Record<string, string> = {
    done: 'Done',
    started: 'Under way',
    pending: 'Waiting',
    failed: 'Failed',
    'switched-back': 'Switched back',
};

/** A length of time as "42 s" or "3 min 40 s". */
export function duration(startedAt: string, finishedAt: string): string {
    const seconds = Math.max(
        0,
        Math.round(
            (new Date(finishedAt).getTime() - new Date(startedAt).getTime()) /
                1000,
        ),
    );
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    if (minutes === 0) {
        return `${rest} s`;
    }

    return rest === 0 ? `${minutes} min` : `${minutes} min ${rest} s`;
}

function Code({ children }: { children: React.ReactNode }) {
    return <code className="bg-muted rounded px-1 py-0.5">{children}</code>;
}

/** Every step, in order, those the updater hasn't recorded yet as waiting. */
function allSteps(update: TemeUpdate): TemeUpdateStep[] {
    return STEPS.map(
        (step) =>
            update.steps.find((entry) => entry.step === step) ?? {
                step,
                state: 'pending',
                startedAt: null,
                finishedAt: null,
                detail: null,
            },
    );
}

/** What a step says, in the demo's words. */
function stepWords(
    step: TemeUpdateStep,
    update: TemeUpdate,
    deploy: UpdatesPage['deploys'][number] | undefined,
): string {
    switch (step.step) {
        case 'deploy': {
            if (step.state !== 'done') {
                return 'deploy';
            }

            const backup = update.backupRun ?? deploy?.backupRun ?? null;

            return backup !== null
                ? `deploy: backup ${backup} taken, ${migrations(deploy?.migrations ?? 0)} ran`
                : 'deploy: no migration was waiting';
        }
        case 'web':
            return `web answers with ${update.to}`;
        case 'worker':
            return 'worker: healthy';
        case 'worker-long':
            return 'worker-long: healthy';
        case 'scheduler':
            return 'scheduler: healthy';
        case 'check':
            return `Last check: every part healthy, and the site answers with ${update.to}`;
        default:
            return `updater: asked to start on ${update.to}`;
    }
}

function migrations(count: number): string {
    return `${count} ${count === 1 ? 'migration' : 'migrations'}`;
}

function StateIcon({ state }: { state: string }) {
    const label = STATE_WORDS[state] ?? state;
    const common = 'mt-0.5 size-4 shrink-0';

    return (
        <>
            {state === 'done' ? (
                <CircleCheck
                    aria-hidden="true"
                    className={`${common} text-success`}
                />
            ) : state === 'started' ? (
                <Loader2
                    aria-hidden="true"
                    className={`${common} animate-spin`}
                />
            ) : state === 'failed' ? (
                <CircleX
                    aria-hidden="true"
                    className={`${common} text-destructive`}
                />
            ) : state === 'switched-back' ? (
                <Undo2
                    aria-hidden="true"
                    className={`${common} text-warning`}
                />
            ) : (
                <Circle
                    aria-hidden="true"
                    className={`${common} text-muted-foreground`}
                />
            )}
            <span className="sr-only">{label}. </span>
        </>
    );
}

/** The steps as an ordered list, each with its state and time. */
function Steps({
    update,
    deploy,
    when,
}: {
    update: TemeUpdate;
    deploy: UpdatesPage['deploys'][number] | undefined;
    when: (iso: string) => string;
}) {
    return (
        <ol className="space-y-2" data-test="update-steps">
            {allSteps(update).map((step) => {
                const time = step.finishedAt ?? step.startedAt;

                return (
                    <li key={step.step} className="flex items-start gap-2">
                        <StateIcon state={step.state} />
                        <span>
                            <span data-test={`update-step-${step.step}`}>
                                {stepWords(step, update, deploy)}
                            </span>
                            {time !== null && (
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {when(time)}
                                </span>
                            )}
                            {step.detail !== null && step.state !== 'done' && (
                                <span className="text-muted-foreground block">
                                    {step.detail}
                                </span>
                            )}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

/** Where a request waits while the person types their password on Fortify's screen. */
const PENDING_KEY = 'teme.updates.pending';

/** A request is carried on only if it was asked for in the last ten minutes. */
const PENDING_MINUTES = 10;

type Pending = { kind: string; version: string; at: number };

/** Storage may be missing or refuse; the page then works as it does with no marker. */
function readPending(): Pending | null {
    try {
        const raw = sessionStorage.getItem(PENDING_KEY);

        return raw === null ? null : (JSON.parse(raw) as Pending);
    } catch {
        return null;
    }
}

function writePending(kind: string, version: string): void {
    try {
        sessionStorage.setItem(
            PENDING_KEY,
            JSON.stringify({ kind, version, at: Date.now() }),
        );
    } catch {
        // The person presses the button again, as without a marker.
    }
}

function removePending(): void {
    try {
        sessionStorage.removeItem(PENDING_KEY);
    } catch {
        // Nothing to remove.
    }
}

/**
 * A request for Update to … or Roll back to … (I-3342). It posts plainly; without a recent password check
 * the server sends the person to the password screen and back here, and the page then makes the request
 * itself, once, from a marker left in sessionStorage. That second post asks in place, so a person who came
 * back without the password gets a 423 and nothing happens.
 */
function CarryingOnRequest({
    version,
    kind,
    label,
    variant = 'default',
    disabled = false,
    describedBy,
    testId,
    below,
}: {
    version: string;
    kind: 'update' | 'rollback';
    label: string;
    variant?: 'default' | 'outline';
    disabled?: boolean;
    describedBy?: string;
    testId: string;
    below?: React.ReactNode;
}) {
    useEffect(() => {
        const pending = readPending();

        if (pending === null) {
            return;
        }

        if (Date.now() - pending.at > PENDING_MINUTES * 60_000) {
            removePending();

            return;
        }

        if (pending.kind !== kind || pending.version !== version) {
            return;
        }

        // Removed first, so a second mount can never send it twice.
        removePending();

        if (disabled) {
            return;
        }

        router.post(
            RequestUpdateController.url(),
            { version, kind },
            {
                headers: { 'X-Teme-Ask-In-Place': '1' },
                onHttpException: (response) => {
                    if (response.status === 423) {
                        return false;
                    }
                },
            },
        );
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <Form
            {...RequestUpdateController.form()}
            onBefore={() => writePending(kind, version)}
            onSuccess={(page) => {
                // Straight through: nothing is pending.
                if (page.component === 'tools/updates') {
                    removePending();
                }
            }}
            className="space-y-1"
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="version" value={version} />
                    <input type="hidden" name="kind" value={kind} />
                    <Button
                        type="submit"
                        data-test={testId}
                        variant={variant}
                        disabled={disabled || processing}
                        aria-describedby={describedBy}
                    >
                        {label}
                    </Button>
                    {below}
                </>
            )}
        </Form>
    );
}

/** The header's Update to … button, with the reason beside it when it is off. */
export function UpdateButton({
    version,
    canUpdate,
    open,
}: {
    version: string;
    canUpdate: string | null;
    open: boolean;
}) {
    const reason = open ? 'An update is under way.' : canUpdate;

    return (
        <CarryingOnRequest
            version={version}
            kind="update"
            label={`Update to ${version}`}
            variant={reason === null ? 'default' : 'outline'}
            disabled={reason !== null}
            describedBy={reason === null ? undefined : 'update-reason'}
            testId="update-now"
            below={
                reason !== null && (
                    <p
                        id="update-reason"
                        className="text-muted-foreground text-xs"
                    >
                        {reason}
                    </p>
                )
            }
        />
    );
}

/** Tools › Updates' block at the top (I-3329): the update asked for, under way, or ended in the last seven days. */
export function UpdateProgress({
    update,
    rollback,
    updaterSeenAt,
    deploys,
    when,
}: {
    update: TemeUpdate;
    rollback: UpdatesPage['rollback'];
    updaterSeenAt: string | null;
    deploys: UpdatesPage['deploys'];
    when: (iso: string) => string;
}) {
    const [shown, setShown] = useState(false);
    const rolling = update.kind === 'rollback';
    const noun = rolling ? 'roll back' : 'update';
    const deploy = deploys.find(
        (run) =>
            run.outcome === 'deployed' &&
            run.version === update.to &&
            (update.startedAt === null || run.finishedAt >= update.startedAt),
    );
    const done = allSteps(update).filter((step) => step.state === 'done');
    const ended = ['done', 'switched_back', 'failed'].includes(update.status);
    const took =
        update.startedAt !== null && update.finishedAt !== null
            ? duration(update.startedAt, update.finishedAt)
            : null;
    const finished = update.finishedAt === null ? '' : when(update.finishedAt);
    const quiet =
        updaterSeenAt === null ||
        Date.now() - new Date(updaterSeenAt).getTime() >
            UPDATER_QUIET_MINUTES * 60_000;
    const backup = update.backupRun ?? deploy?.backupRun ?? null;

    return (
        <section
            aria-labelledby="update-lead"
            data-test="update-block"
            data-status={update.status}
            className="border-border space-y-3 rounded-md border p-4 text-[13px]"
        >
            {update.status === 'requested' && (
                <>
                    <p
                        id="update-lead"
                        role="status"
                        className="text-base font-medium"
                    >
                        {update.requestedBy === null
                            ? `${rolling ? 'Roll back' : 'Update'} to Teme ${update.to} requested at the server at ${when(update.requestedAt)}. The updater starts it within a minute.`
                            : `${rolling ? 'Roll back' : 'Update'} to Teme ${update.to} requested by ${update.requestedBy} at ${when(update.requestedAt)}. The updater starts it within a minute.`}
                    </p>
                    {quiet && (
                        <p className="text-muted-foreground">
                            {updaterSeenAt === null
                                ? 'No updater has looked for requests yet. Without one, update by hand: see How to update.'
                                : `No updater has looked for requests since ${when(updaterSeenAt)}. Without one, update by hand: see How to update.`}
                        </p>
                    )}
                    {update.canCancel && (
                        <Form
                            {...CancelUpdateController.form({
                                update: update.id,
                            })}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    data-test="update-cancel"
                                    disabled={processing}
                                >
                                    Cancel the update
                                </Button>
                            )}
                        </Form>
                    )}
                </>
            )}

            {update.status === 'running' && (
                <>
                    <h2 id="update-lead" className="text-base font-medium">
                        {rolling ? 'Rolling back' : 'Updating'} to Teme{' '}
                        {update.to}
                    </h2>
                    <p role="status">
                        Started {when(update.startedAt ?? update.requestedAt)} ·{' '}
                        {duration(
                            update.startedAt ?? update.requestedAt,
                            new Date().toISOString(),
                        )}{' '}
                        so far. The site is down for a short while as the
                        service deploys, then each part is checked on the new
                        release.
                    </p>
                    <div className="space-y-1">
                        <progress
                            value={done.length}
                            max={STEPS.length}
                            aria-label={`${done.length} of 7 steps`}
                            className="w-full"
                        />
                        <p className="text-muted-foreground">
                            {done.length} of 7 steps
                        </p>
                    </div>
                    <Steps update={update} deploy={deploy} when={when} />
                </>
            )}

            {update.status === 'done' && (
                <>
                    <p
                        id="update-lead"
                        role="status"
                        className="text-base font-medium"
                    >
                        {rolling
                            ? `Rolled back to Teme ${update.to} at ${finished}.`
                            : `Updated to Teme ${update.to} at ${finished}. It took ${took ?? ''}${
                                  backup !== null && deploy !== undefined
                                      ? `; ${migrations(deploy.migrations)} ran after a backup.`
                                      : '.'
                              }`}
                    </p>
                    {!rolling &&
                        rollback !== null &&
                        (rollback.refusal === null ? (
                            <div className="space-y-2">
                                <p className="text-muted-foreground">
                                    Rolling back switches every part to{' '}
                                    {rollback.version} again. The database keeps{' '}
                                    {update.to}’s additions, which{' '}
                                    {rollback.version} runs beside.
                                </p>
                                <CarryingOnRequest
                                    version={rollback.version}
                                    kind="rollback"
                                    label={`Roll back to ${rollback.version}`}
                                    variant="outline"
                                    testId="update-rollback"
                                />
                            </div>
                        ) : (
                            <p className="text-muted-foreground">
                                {rollback.refusal}
                            </p>
                        ))}
                </>
            )}

            {update.status === 'switched_back' && (
                <>
                    <p
                        id="update-lead"
                        role="status"
                        className="text-base font-medium"
                    >
                        The {noun} to Teme {update.to} was switched back at{' '}
                        {finished}. {update.error ?? ''} So every part runs{' '}
                        {update.from} again.
                    </p>
                    <p className="text-muted-foreground">
                        The database keeps {update.to}’s additions, which{' '}
                        {update.from} runs beside. Nothing else needs doing.
                    </p>
                </>
            )}

            {update.status === 'failed' && (
                <>
                    <p
                        id="update-lead"
                        role="status"
                        className="text-base font-medium"
                    >
                        The {noun} to Teme {update.to} stopped:{' '}
                        {update.error ?? ''} The site runs {update.from}.
                    </p>
                    {backup !== null && (
                        <p>
                            A backup was taken just before: {backup}. If the
                            database needs it, restore it at the server:{' '}
                            <Code>php artisan teme:backup:restore</Code>, then
                            choose that backup.
                        </p>
                    )}
                </>
            )}

            {ended && (
                <div className="space-y-2">
                    <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0 text-[13px]"
                        data-test="update-steps-toggle"
                        aria-expanded={shown}
                        aria-controls="update-what-happened"
                        onClick={() => setShown(!shown)}
                    >
                        {shown ? 'Hide the steps' : 'See what happened'}
                    </Button>
                    <div id="update-what-happened" hidden={!shown}>
                        {shown && (
                            <Steps
                                update={update}
                                deploy={deploy}
                                when={when}
                            />
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
