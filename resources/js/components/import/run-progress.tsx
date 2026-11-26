import { Form } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import { FigureTiles } from '@/components/tools/figure-tiles';
import type { FigureTile } from '@/components/tools/figure-tiles';
import { PhaseList } from '@/components/tools/phase-list';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { Progress } from '@/components/ui/progress';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { JobRow } from '@/types/jobs';
import type { ImportRunView } from '@/types/import';

/**
 * The import’s progress card (issue 699): phase line, bar, the four counts
 * once, and Stop / Carry on / See the report. Steps live under the Progress
 * tab via RunProgressSteps.
 */
export function RunProgress({
    run,
    job,
    onSeeReport,
}: {
    run: ImportRunView;
    job: JobRow | null;
    /** Opens the Report tab when the import has finished. */
    onSeeReport?: () => void;
}) {
    const running = run.state === 'running';
    const finished = run.state === 'finished';
    const { counts } = run;
    const waiting = running && job !== null && job.state === 'waiting';
    const at = run.steps.findIndex((step) => step.state === 'now');
    const percent = job?.percent ?? (finished ? 100 : null);
    const phasesLeft = run.steps.filter((step) => step.state !== 'done').length;
    const figures: FigureTile[] = [
        {
            key: 'imported',
            label: __('Imported'),
            value: counts.created + counts.updated,
            tone: 'ok',
        },
        {
            key: 'warnings',
            label: __('With warnings'),
            value: counts.warnings,
            tone: 'warn',
        },
        {
            key: 'failed',
            label: __('Failed'),
            value: counts.failed,
            tone: 'danger',
        },
        {
            key: 'left',
            label: __('Phases left'),
            value: finished ? 0 : phasesLeft,
            tone: 'neutral',
        },
    ];
    const where = finished
        ? __('Finished')
        : waiting
          ? __('Waiting for a free worker')
          : !running
            ? percent === null
                ? __('Stopped')
                : __('Stopped at :percent%', { percent })
            : at === -1
              ? __('Importing')
              : __('Phase :number of :count: :phase', {
                    number: at + 1,
                    count: run.steps.length,
                    phase: run.steps[at].label,
                });

    return (
        <Panel aria-labelledby="import-progress-heading">
            <PanelHeader className="flex items-center justify-between gap-3">
                <h2
                    id="import-progress-heading"
                    className="text-body font-semibold"
                >
                    {finished
                        ? __('Finished')
                        : running
                          ? __('Importing')
                          : __('Import stopped')}
                </h2>
                <StatusPill
                    tone={
                        finished
                            ? 'ok'
                            : waiting
                              ? 'neutral'
                              : running
                                ? 'info'
                                : 'warn'
                    }
                >
                    {finished
                        ? __('Finished')
                        : waiting
                          ? __('Waiting')
                          : running
                            ? __('Running')
                            : __('Stopped')}
                </StatusPill>
            </PanelHeader>
            <PanelBody className="text-body gap-5">
                <div className="grid gap-2" data-test="import-progress">
                    <div className="text-small text-ink-2 flex justify-between gap-3 tabular-nums">
                        <span>{where}</span>
                        {percent !== null && !waiting && (
                            <span>{__(':percent%', { percent })}</span>
                        )}
                    </div>
                    {percent !== null && (
                        <Progress
                            value={percent}
                            tone={
                                finished ? 'ok' : running ? 'accent' : 'quiet'
                            }
                            label={__('Progress of the import')}
                        />
                    )}
                </div>

                {!running && !finished && run.stopMessage !== null && (
                    <p>{run.stopMessage}</p>
                )}

                <FigureTiles
                    figures={figures}
                    label={__('Counts so far')}
                    testId="import-counts"
                />

                {finished ? (
                    <div className="grid justify-items-start gap-2">
                        <Button
                            type="button"
                            data-test="import-see-report"
                            onClick={onSeeReport}
                        >
                            {__('See the report')}
                        </Button>
                    </div>
                ) : running ? (
                    <div className="grid justify-items-start gap-2">
                        <p className="text-ink-2">
                            {__(
                                'You can leave this page. The import carries on in the background, and the result reaches your inbox.',
                            )}
                        </p>
                        <Form
                            {...ImportRunController.stop.form({ run: run.id })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    data-test="import-stop"
                                    disabled={processing}
                                >
                                    {__('Stop the import')}
                                </Button>
                            )}
                        </Form>
                    </div>
                ) : (
                    <div className="grid justify-items-start gap-2">
                        <Form
                            {...ImportRunController.carryOn.form({
                                run: run.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    data-test="import-carry-on"
                                    disabled={processing}
                                >
                                    {__('Carry on')}
                                </Button>
                            )}
                        </Form>
                        <p className="text-ink-2">
                            {__(
                                'Carrying on picks up where it stopped. Teme asks for your password first.',
                            )}
                        </p>
                    </div>
                )}
            </PanelBody>
        </Panel>
    );
}

/**
 * The Progress tab’s folded steps: Processed N, the current step, Waiting N,
 * and a link to the Report tab’s warnings.
 */
export function RunProgressSteps({
    run,
    job,
    warningCount,
    onReviewWarnings,
}: {
    run: ImportRunView;
    job: JobRow | null;
    warningCount: number;
    onReviewWarnings?: () => void;
}) {
    const running = run.state === 'running';
    const waiting = running && job !== null && job.state === 'waiting';
    const done = run.steps.filter((step) => step.state === 'done');
    const current = run.steps.find((step) => step.state === 'now');
    const next = run.steps.filter(
        (step) => step.state !== 'done' && step.state !== 'now',
    );

    const asPhase = (
        step: ImportRunView['steps'][number],
        state: 'done' | 'now' | 'next' | 'stopped',
        word: string,
    ) => ({
        key: step.key,
        label: step.label,
        state,
        word,
    });

    return (
        <div className="grid gap-3" data-test="import-progress-steps">
            {done.length > 0 && (
                <Collapsible defaultOpen={false}>
                    <CollapsibleTrigger className="text-body text-ink-2 hover:bg-surface-2 focus-visible:outline-ring group flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-start font-medium focus-visible:outline-2">
                        <span>
                            {__('Processed :count', {
                                count: done.length.toLocaleString(),
                            })}
                        </span>
                        <ChevronDown
                            aria-hidden="true"
                            className="size-4 transition-transform group-data-[state=open]:rotate-180"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="pt-1">
                        <PhaseList
                            label={__('Processed steps')}
                            phases={done.map((step) =>
                                asPhase(step, 'done', __('Done')),
                            )}
                        />
                    </CollapsibleContent>
                </Collapsible>
            )}

            {current !== undefined && (
                <PhaseList
                    label={__('Current step')}
                    phases={[
                        asPhase(
                            current,
                            running && !waiting
                                ? 'now'
                                : running
                                  ? 'next'
                                  : run.state === 'finished'
                                    ? 'done'
                                    : 'stopped',
                            running && !waiting
                                ? __('Under way')
                                : running
                                  ? __('Waiting')
                                  : run.state === 'finished'
                                    ? __('Done')
                                    : __('Stopped'),
                        ),
                    ]}
                />
            )}

            {next.length > 0 && (
                <Collapsible defaultOpen={false}>
                    <CollapsibleTrigger className="text-body text-ink-2 hover:bg-surface-2 focus-visible:outline-ring group flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-start font-medium focus-visible:outline-2">
                        <span>
                            {__('Waiting :count', {
                                count: next.length.toLocaleString(),
                            })}
                        </span>
                        <ChevronDown
                            aria-hidden="true"
                            className="size-4 transition-transform group-data-[state=open]:rotate-180"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="pt-1">
                        <PhaseList
                            label={__('Waiting steps')}
                            phases={next.map((step) =>
                                asPhase(step, 'next', __('Waiting')),
                            )}
                        />
                    </CollapsibleContent>
                </Collapsible>
            )}

            {warningCount > 0 && onReviewWarnings !== undefined && (
                <p>
                    <TextButton
                        type="button"
                        data-test="import-review-warnings"
                        onClick={onReviewWarnings}
                    >
                        {__('Review warnings')}
                    </TextButton>
                </p>
            )}
        </div>
    );
}
