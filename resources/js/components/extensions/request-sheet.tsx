import { Circle, CircleCheck, CircleX, Hourglass, Loader2 } from 'lucide-react';
import type { RequestPayload } from '@inertiajs/core';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import ExtensionsController from '@/actions/App/Http/Admin/ExtensionsController';
import { send } from '@/components/editor/editor-http';
import { CommandsBox } from '@/components/extensions/commands-box';
import { useAsk } from '@/components/extensions/use-ask';
import {
    STEPS,
    actionLabel,
    needsGroups,
    sourceLabel,
    statusLabel,
    statusTone,
    stepLabel,
    stepStateLabel,
} from '@/components/extensions/words';
import { ResponsiveSheetContent } from '@/components/patterns/responsive-sheet';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Progress } from '@/components/ui/progress';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { ExtensionRequestDetail, ExtensionStep } from '@/types';
import { formatDateTime } from '@/lib/dates';

/** How often an open request is read again while its sheet is open, as Tools › Updates does. */
const POLL_MS = 3000;

/** A moment on the site's clock, in the admin's language. */
function when(iso: string): string {
    return formatDateTime(iso);
}

/** Every step, in order, those the installer hasn't recorded yet as not yet. */
function allSteps(request: ExtensionRequestDetail): ExtensionStep[] {
    return STEPS.map(
        (step) =>
            request.steps.find((entry) => entry.step === step) ?? {
                step,
                state: 'pending',
                startedAt: null,
                finishedAt: null,
                detail: null,
            },
    );
}

function StepIcon({ state }: { state: string }) {
    const common = 'size-4 shrink-0';

    if (state === 'done') {
        return (
            <CircleCheck
                aria-hidden="true"
                className={`${common} text-ok-ink`}
            />
        );
    }

    if (state === 'running') {
        return (
            <Loader2
                aria-hidden="true"
                className={`${common} motion-safe:animate-spin`}
            />
        );
    }

    if (state === 'failed') {
        return (
            <CircleX
                aria-hidden="true"
                className={`${common} text-danger-ink`}
            />
        );
    }

    if (state === 'waiting') {
        return (
            <Hourglass
                aria-hidden="true"
                className={`${common} text-warn-ink`}
            />
        );
    }

    return <Circle aria-hidden="true" className={`${common} text-ink-2`} />;
}

/** One fact of the request: a term and what it holds. */
function Fact({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="grid min-w-0 gap-0.5 sm:flex sm:gap-3">
            <dt className="text-small text-ink-2 sm:w-32 sm:shrink-0">
                {term}
            </dt>
            <dd className="text-body min-w-0 break-words">{children}</dd>
        </div>
    );
}

/** Composer's last lines, when the installer kept them. */
function lastLines(report: Record<string, unknown>): string | null {
    for (const key of ['output', 'composer', 'lines']) {
        const value = report[key];

        if (typeof value === 'string' && value !== '') {
            return value;
        }

        if (Array.isArray(value) && value.length > 0) {
            return value.map(String).join('\n');
        }
    }

    return null;
}

/**
 * A request's sheet (I-211): what was asked, by whom and when, then the
 * installer's numbered steps with a progress bar, read again every three
 * seconds while the request is open. Waiting for agreement, it lists what to
 * agree to with Agree and continue and Cancel request; failed, the step and
 * why; with no installer running, the commands that carry it out.
 */
export function RequestSheet({
    requestId,
    initial,
    installerPresent: present,
    onClose,
}: {
    requestId: string | null;
    initial: ExtensionRequestDetail | null;
    installerPresent: boolean;
    onClose: () => void;
}) {
    const [request, setRequest] = useState<ExtensionRequestDetail | null>(
        initial !== null && initial.id === requestId ? initial : null,
    );
    const [installerPresent, setInstallerPresent] = useState(present);
    const { ask, busy, password } = useAsk();
    const open = requestId !== null;
    const live =
        request === null ||
        ['requested', 'running', 'waiting'].includes(request.status);

    useEffect(() => {
        if (requestId === null) {
            setRequest(null);

            return;
        }

        if (!live && request?.id === requestId) {
            return;
        }

        let stopped = false;

        const read = async () => {
            const answer = await send(ExtensionsController.show(requestId));

            if (!stopped && answer.status === 200) {
                setRequest(answer.body.request as ExtensionRequestDetail);
                setInstallerPresent(answer.body.installerPresent === true);
            }
        };

        void read();
        const timer = window.setInterval(() => void read(), POLL_MS);

        return () => {
            stopped = true;
            window.clearInterval(timer);
        };
        // Read again only when another request is opened, or this one ends.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [requestId, live]);

    const steps = request === null ? [] : allSteps(request);
    const done = steps.filter((step) => step.state === 'done').length;
    const failed = steps.find((step) => step.state === 'failed');

    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <ResponsiveSheetContent data-test="extension-request-sheet">
                <SheetHeader>
                    <SheetTitle>
                        {request?.package ??
                            (request !== null
                                ? actionLabel(request.action)
                                : __('Request'))}
                    </SheetTitle>
                    <SheetDescription>
                        {__(
                            'You can close this sheet: the installer carries on.',
                        )}
                    </SheetDescription>
                </SheetHeader>
                <ScrollArea className="min-h-0 flex-1">
                    {request !== null && (
                        <div className="grid gap-5 px-6 pb-4">
                            <p>
                                <StatusPill
                                    tone={statusTone(request.status)}
                                    data-test="extension-request-status"
                                >
                                    {statusLabel(request.status)}
                                </StatusPill>
                            </p>
                            <dl className="grid gap-2">
                                <Fact term={__('Request')}>
                                    {actionLabel(request.action)}
                                </Fact>
                                <Fact term={__('From')}>
                                    {sourceLabel(request.sourceKind)}
                                </Fact>
                                {request.constraint !== null && (
                                    <Fact term={__('Version')}>
                                        <span className="font-mono">
                                            {typeof request.report.version ===
                                            'string'
                                                ? __(
                                                      ':version (asked for :asked)',
                                                      {
                                                          version:
                                                              request.report
                                                                  .version,
                                                          asked: request.constraint,
                                                      },
                                                  )
                                                : __('Asked for :asked', {
                                                      asked: request.constraint,
                                                  })}
                                        </span>
                                    </Fact>
                                )}
                                <Fact term={__('Asked')}>
                                    {request.requestedBy === null
                                        ? __(':when, at the server', {
                                              when: when(request.requestedAt),
                                          })
                                        : __(':when, by :name', {
                                              when: when(request.requestedAt),
                                              name: request.requestedBy,
                                          })}
                                </Fact>
                            </dl>

                            <section
                                className="grid gap-3"
                                aria-labelledby="extension-steps-title"
                            >
                                <h3
                                    id="extension-steps-title"
                                    className="text-body font-semibold"
                                >
                                    {__('The installer’s steps')}
                                </h3>
                                <Progress
                                    value={done}
                                    max={STEPS.length}
                                    tone={
                                        failed !== undefined
                                            ? 'danger'
                                            : 'accent'
                                    }
                                    label={__('Steps done')}
                                />
                                <ol
                                    className="grid gap-2"
                                    data-test="extension-steps"
                                >
                                    {steps.map((step, at) => (
                                        <li
                                            key={step.step}
                                            className="flex items-start gap-2"
                                        >
                                            <span className="flex h-5 items-center">
                                                <StepIcon state={step.state} />
                                            </span>
                                            <span className="min-w-0">
                                                <span
                                                    data-test={`extension-step-${step.step}`}
                                                >
                                                    {`${at + 1}. ${stepLabel(step.step)}`}
                                                </span>
                                                <span className="text-ink-2">
                                                    {' · '}
                                                    {stepStateLabel(step.state)}
                                                </span>
                                                {step.detail !== null && (
                                                    <span className="text-small text-ink-2 block break-words">
                                                        {step.detail}
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            </section>

                            {request.status === 'failed' && (
                                <Notice
                                    tone="danger"
                                    title={
                                        failed !== undefined
                                            ? __('Failed at “:step”', {
                                                  step: stepLabel(failed.step),
                                              })
                                            : __('The request failed')
                                    }
                                    data-test="extension-request-failed"
                                >
                                    <p className="break-words">
                                        {request.error ?? ''}
                                    </p>
                                    {lastLines(request.report) !== null && (
                                        <pre className="text-small mt-2 font-mono break-all whitespace-pre-wrap">
                                            {lastLines(request.report)}
                                        </pre>
                                    )}
                                </Notice>
                            )}

                            {request.status === 'waiting' && (
                                <section
                                    className="grid gap-3"
                                    data-test="extension-request-needs"
                                    aria-labelledby="extension-needs-title"
                                >
                                    <h3
                                        id="extension-needs-title"
                                        className="text-body font-semibold"
                                    >
                                        {__(
                                            'The installer needs your agreement',
                                        )}
                                    </h3>
                                    {needsGroups(request.needs).map((group) => (
                                        <div
                                            key={group.key}
                                            className="grid gap-1"
                                        >
                                            <p className="text-small font-medium">
                                                {group.heading}
                                            </p>
                                            <ul className="text-small text-ink-2 list-disc ps-5">
                                                {group.lines.map((line) => (
                                                    <li
                                                        key={line}
                                                        className="break-words"
                                                    >
                                                        {line}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ))}
                                </section>
                            )}

                            {request.status === 'requested' &&
                                !installerPresent &&
                                request.commands !== null && (
                                    <CommandsBox commands={request.commands} />
                                )}
                        </div>
                    )}
                </ScrollArea>
                {request !== null &&
                    (request.canAgree || request.canCancel) && (
                        <SheetFooter>
                            {request.canCancel && (
                                <Button
                                    type="button"
                                    variant="neutral"
                                    loading={busy}
                                    data-test="extension-request-cancel"
                                    onClick={() =>
                                        ask({
                                            url: ExtensionsController.cancel.url(
                                                request.id,
                                            ),
                                            onDone: onClose,
                                        })
                                    }
                                >
                                    {__('Cancel request')}
                                </Button>
                            )}
                            {request.canAgree && (
                                <Button
                                    type="button"
                                    loading={busy}
                                    data-test="extension-request-agree"
                                    onClick={() =>
                                        ask({
                                            url: ExtensionsController.agree.url(
                                                request.id,
                                            ),
                                            data: {
                                                needs: request.needs,
                                            } as RequestPayload,
                                            onDone: () =>
                                                setRequest({
                                                    ...request,
                                                    agreed: request.needs,
                                                    canAgree: false,
                                                }),
                                        })
                                    }
                                >
                                    {__('Agree and continue')}
                                </Button>
                            )}
                        </SheetFooter>
                    )}
                {password}
            </ResponsiveSheetContent>
        </Sheet>
    );
}
