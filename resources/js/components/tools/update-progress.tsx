import { Form, router } from '@inertiajs/react';
import { Circle, CircleCheck, CircleX, Loader2, Undo2 } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import CancelUpdateController from '@/actions/App/Http/Admin/Tools/Updates/CancelUpdateController';
import RequestUpdateController from '@/actions/App/Http/Admin/Tools/Updates/RequestUpdateController';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody } from '@/components/ui/panel';
import { Progress } from '@/components/ui/progress';
import { TextButton } from '@/components/ui/text-button';
import { __, __n } from '@/lib/i18n';
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

/** The command that restores a backup, typed at the server as it is. */
const RESTORE_COMMAND = 'php artisan teme:backup:restore';

/** How each state of a step reads to a screen reader, looked up as it is drawn. */
function stateWords(state: string): string {
    const words: Record<string, string> = {
        done: __('Done'),
        started: __('Under way'),
        pending: __('Waiting'),
        failed: __('Failed'),
        'switched-back': __('Switched back'),
    };

    return words[state] ?? state;
}

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
        return __(':seconds s', { seconds: rest });
    }

    return rest === 0
        ? __(':minutes min', { minutes })
        : __(':minutes min :seconds s', { minutes, seconds: rest });
}

/** A command or a setting's name, as it is typed. */
export function Code({ children }: { children: ReactNode }) {
    return <code className="bg-surface-2 rounded-sm px-1">{children}</code>;
}

/**
 * A translated sentence with commands or settings' names in it: each `:name`
 * the words hold is drawn as `Code`, so the whole sentence stays one line to
 * translate.
 */
export function WithCode({
    words,
    codes,
}: {
    words: string;
    codes: Record<string, string>;
}) {
    return (
        <>
            {words
                .split(/(:[A-Za-z]+)/)
                .map((part, at) =>
                    part.startsWith(':') && part.slice(1) in codes ? (
                        <Code key={at}>{codes[part.slice(1)]}</Code>
                    ) : (
                        <Fragment key={at}>{part}</Fragment>
                    ),
                )}
        </>
    );
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
                return __('deploy');
            }

            const backup = update.backupRun ?? deploy?.backupRun ?? null;

            return backup !== null
                ? __n(
                      'deploy: backup :backup taken, :count migration ran|deploy: backup :backup taken, :count migrations ran',
                      deploy?.migrations ?? 0,
                      { backup },
                  )
                : __('deploy: no migration was waiting');
        }
        case 'web':
            return __('web answers with :version', { version: update.to });
        case 'worker':
            return __('worker: healthy');
        case 'worker-long':
            return __('worker-long: healthy');
        case 'scheduler':
            return __('scheduler: healthy');
        case 'check':
            return __(
                'Last check: every part healthy, and the site answers with :version',
                { version: update.to },
            );
        default:
            return __('updater: asked to start on :version', {
                version: update.to,
            });
    }
}

function StateIcon({ state }: { state: string }) {
    const common = 'size-4 shrink-0';

    return (
        <>
            <span className="flex h-5 items-center">
                {state === 'done' ? (
                    <CircleCheck
                        aria-hidden="true"
                        className={`${common} text-ok-ink`}
                    />
                ) : state === 'started' ? (
                    <Loader2
                        aria-hidden="true"
                        className={`${common} motion-safe:animate-spin`}
                    />
                ) : state === 'failed' ? (
                    <CircleX
                        aria-hidden="true"
                        className={`${common} text-danger-ink`}
                    />
                ) : state === 'switched-back' ? (
                    <Undo2
                        aria-hidden="true"
                        className={`${common} text-warn-ink`}
                    />
                ) : (
                    <Circle
                        aria-hidden="true"
                        className={`${common} text-ink-2`}
                    />
                )}
            </span>
            <span className="sr-only">{`${stateWords(state)}. `}</span>
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
        <ol className="grid gap-2" data-test="update-steps">
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
                                <span className="text-ink-2">
                                    {' · '}
                                    {when(time)}
                                </span>
                            )}
                            {step.detail !== null && step.state !== 'done' && (
                                <span className="text-ink-2 block">
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
            className="grid justify-items-start gap-1"
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
    const reason = open ? __('An update is under way.') : canUpdate;

    return (
        <CarryingOnRequest
            version={version}
            kind="update"
            label={__('Update to :version', { version })}
            variant={reason === null ? 'default' : 'outline'}
            disabled={reason !== null}
            describedBy={reason === null ? undefined : 'update-reason'}
            testId="update-now"
            below={
                reason !== null && (
                    <p id="update-reason" className="text-ink-2 text-small">
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
    const deploy = deploys.find(
        (run) =>
            run.outcome === 'deployed' &&
            run.version === update.to &&
            (update.startedAt === null || run.finishedAt >= update.startedAt),
    );
    const steps = allSteps(update);
    const done = steps.filter((step) => step.state === 'done');
    const ended = ['done', 'switched_back', 'failed'].includes(update.status);
    const took =
        update.startedAt !== null && update.finishedAt !== null
            ? duration(update.startedAt, update.finishedAt)
            : '';
    const finished = update.finishedAt === null ? '' : when(update.finishedAt);
    const quiet =
        updaterSeenAt === null ||
        Date.now() - new Date(updaterSeenAt).getTime() >
            UPDATER_QUIET_MINUTES * 60_000;
    const backup = update.backupRun ?? deploy?.backupRun ?? null;
    const lead = 'text-body font-semibold';

    return (
        <Panel
            aria-labelledby="update-lead"
            data-test="update-block"
            data-status={update.status}
        >
            <PanelBody className="text-body pt-5">
                {update.status === 'requested' && (
                    <>
                        <p id="update-lead" role="status" className={lead}>
                            {update.requestedBy === null
                                ? rolling
                                    ? __(
                                          'Roll back to Teme :version requested at the server at :time. The updater starts it within a minute.',
                                          {
                                              version: update.to,
                                              time: when(update.requestedAt),
                                          },
                                      )
                                    : __(
                                          'Update to Teme :version requested at the server at :time. The updater starts it within a minute.',
                                          {
                                              version: update.to,
                                              time: when(update.requestedAt),
                                          },
                                      )
                                : rolling
                                  ? __(
                                        'Roll back to Teme :version requested by :name at :time. The updater starts it within a minute.',
                                        {
                                            version: update.to,
                                            name: update.requestedBy,
                                            time: when(update.requestedAt),
                                        },
                                    )
                                  : __(
                                        'Update to Teme :version requested by :name at :time. The updater starts it within a minute.',
                                        {
                                            version: update.to,
                                            name: update.requestedBy,
                                            time: when(update.requestedAt),
                                        },
                                    )}
                        </p>
                        {quiet && (
                            <p className="text-ink-2">
                                {updaterSeenAt === null
                                    ? __(
                                          'No updater has looked for requests yet. Without one, update by hand: see How to update.',
                                      )
                                    : __(
                                          'No updater has looked for requests since :time. Without one, update by hand: see How to update.',
                                          { time: when(updaterSeenAt) },
                                      )}
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
                                        {__('Cancel the update')}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </>
                )}

                {update.status === 'running' && (
                    <>
                        <h2
                            id="update-lead"
                            className="text-lead font-semibold"
                        >
                            {rolling
                                ? __('Rolling back to Teme :version', {
                                      version: update.to,
                                  })
                                : __('Updating to Teme :version', {
                                      version: update.to,
                                  })}
                        </h2>
                        <p role="status">
                            {__(
                                'Started :time · :took so far. The site is down for a short while as the service deploys, then each part is checked on the new release.',
                                {
                                    time: when(
                                        update.startedAt ?? update.requestedAt,
                                    ),
                                    took: duration(
                                        update.startedAt ?? update.requestedAt,
                                        new Date().toISOString(),
                                    ),
                                },
                            )}
                        </p>
                        <div className="grid gap-1">
                            <Progress
                                value={done.length}
                                max={STEPS.length}
                                tone={
                                    steps.some(
                                        (step) => step.state === 'failed',
                                    )
                                        ? 'danger'
                                        : done.length === STEPS.length
                                          ? 'ok'
                                          : 'accent'
                                }
                                label={__(':done of :total steps', {
                                    done: done.length,
                                    total: STEPS.length,
                                })}
                            />
                            <p className="text-ink-2 text-small">
                                {__(':done of :total steps', {
                                    done: done.length,
                                    total: STEPS.length,
                                })}
                            </p>
                        </div>
                        <Steps update={update} deploy={deploy} when={when} />
                    </>
                )}

                {update.status === 'done' && (
                    <>
                        <p id="update-lead" role="status" className={lead}>
                            {rolling
                                ? __('Rolled back to Teme :version at :time.', {
                                      version: update.to,
                                      time: finished,
                                  })
                                : backup !== null && deploy !== undefined
                                  ? __n(
                                        'Updated to Teme :version at :time. It took :took; :count migration ran after a backup.|Updated to Teme :version at :time. It took :took; :count migrations ran after a backup.',
                                        deploy.migrations,
                                        {
                                            version: update.to,
                                            time: finished,
                                            took,
                                        },
                                    )
                                  : __(
                                        'Updated to Teme :version at :time. It took :took.',
                                        {
                                            version: update.to,
                                            time: finished,
                                            took,
                                        },
                                    )}
                        </p>
                        {!rolling &&
                            rollback !== null &&
                            (rollback.refusal === null ? (
                                <div className="grid gap-2">
                                    <p className="text-ink-2">
                                        {__(
                                            'Rolling back switches every part to :back again. The database keeps :version’s additions, which :back runs beside.',
                                            {
                                                back: rollback.version,
                                                version: update.to,
                                            },
                                        )}
                                    </p>
                                    <CarryingOnRequest
                                        version={rollback.version}
                                        kind="rollback"
                                        label={__('Roll back to :version', {
                                            version: rollback.version,
                                        })}
                                        variant="outline"
                                        testId="update-rollback"
                                    />
                                </div>
                            ) : (
                                <p className="text-ink-2">{rollback.refusal}</p>
                            ))}
                    </>
                )}

                {update.status === 'switched_back' && (
                    <>
                        <p id="update-lead" role="status" className={lead}>
                            {rolling
                                ? __(
                                      'The roll back to Teme :version was switched back at :time. :error So every part runs :from again.',
                                      {
                                          version: update.to,
                                          time: finished,
                                          error: update.error ?? '',
                                          from: update.from,
                                      },
                                  )
                                : __(
                                      'The update to Teme :version was switched back at :time. :error So every part runs :from again.',
                                      {
                                          version: update.to,
                                          time: finished,
                                          error: update.error ?? '',
                                          from: update.from,
                                      },
                                  )}
                        </p>
                        <p className="text-ink-2">
                            {__(
                                'The database keeps :version’s additions, which :from runs beside. Nothing else needs doing.',
                                { version: update.to, from: update.from },
                            )}
                        </p>
                    </>
                )}

                {update.status === 'failed' && (
                    <>
                        <p id="update-lead" role="status" className={lead}>
                            {rolling
                                ? __(
                                      'The roll back to Teme :version stopped: :error The site runs :from.',
                                      {
                                          version: update.to,
                                          error: update.error ?? '',
                                          from: update.from,
                                      },
                                  )
                                : __(
                                      'The update to Teme :version stopped: :error The site runs :from.',
                                      {
                                          version: update.to,
                                          error: update.error ?? '',
                                          from: update.from,
                                      },
                                  )}
                        </p>
                        {backup !== null && (
                            <p>
                                <WithCode
                                    words={__(
                                        'A backup was taken just before: :backup. If the database needs it, restore it at the server: :command, then choose that backup.',
                                        { backup },
                                    )}
                                    codes={{ command: RESTORE_COMMAND }}
                                />
                            </p>
                        )}
                    </>
                )}

                {ended && (
                    <div className="grid justify-items-start gap-2">
                        <TextButton
                            data-test="update-steps-toggle"
                            aria-expanded={shown}
                            aria-controls="update-what-happened"
                            onClick={() => setShown(!shown)}
                        >
                            {shown
                                ? __('Hide the steps')
                                : __('See what happened')}
                        </TextButton>
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
            </PanelBody>
        </Panel>
    );
}
