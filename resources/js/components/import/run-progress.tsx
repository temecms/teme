import { Form } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import { JobProgress } from '@/components/jobs/job-progress';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { StepBar } from '@/components/ui/step-bar';
import { __ } from '@/lib/i18n';
import type { JobRow } from '@/types/jobs';
import type { ImportRunView } from '@/types/import';

/**
 * Where the run's steps stand on the step bar: the step under way, else the
 * first not yet done, else past the last when every one is done.
 */
function currentStep(steps: ImportRunView['steps']): number {
    const now = steps.findIndex((step) => step.state === 'now');

    if (now !== -1) {
        return now;
    }

    const next = steps.findIndex((step) => step.state !== 'done');

    return next === -1 ? steps.length : next;
}

/**
 * A stopped run's steps: those it finished marked done, and none current,
 * since nothing is under way. The step bar always marks one current.
 */
function StoppedSteps({ steps }: { steps: ImportRunView['steps'] }) {
    return (
        <ol aria-label={__('Steps of this import')} className="grid gap-1">
            {steps.map((step) => (
                <li
                    key={step.key}
                    data-state={step.state === 'done' ? 'done' : 'upcoming'}
                    className="text-small text-ink-2 flex items-center gap-2"
                >
                    {step.state === 'done' ? (
                        <>
                            <CheckIcon
                                aria-hidden="true"
                                className="text-ok-ink size-4 shrink-0"
                            />
                            <span className="sr-only">{__('Done')}</span>
                        </>
                    ) : (
                        <span aria-hidden="true" className="size-4 shrink-0" />
                    )}
                    {step.label}
                </li>
            ))}
        </ol>
    );
}

/**
 * An import under way or stopped (I-227): its tracked job's progress, its
 * steps, its counts so far, and Stop the import or Carry on.
 */
export function RunProgress({
    run,
    job,
}: {
    run: ImportRunView;
    job: JobRow | null;
}) {
    const running = run.state === 'running';
    const { counts } = run;

    return (
        <Panel aria-labelledby="import-progress-heading">
            <PanelHeader>
                <h2
                    id="import-progress-heading"
                    className="text-lead font-semibold"
                >
                    {running ? __('Importing') : __('Stopped')}
                </h2>
            </PanelHeader>
            <PanelBody className="text-body gap-4">
                {job !== null && <JobProgress job={job} />}

                {!running && run.stopMessage !== null && (
                    <p>{run.stopMessage}</p>
                )}

                {running ? (
                    <StepBar
                        steps={run.steps.map((step) => step.label)}
                        current={currentStep(run.steps)}
                        aria-label={__('Steps of this import')}
                    />
                ) : (
                    <StoppedSteps steps={run.steps} />
                )}

                <p className="tabular-nums">
                    {__(
                        ':created created · :updated updated · :skipped skipped · :failed failed · :warnings with warnings',
                        {
                            created: counts.created.toLocaleString(),
                            updated: counts.updated.toLocaleString(),
                            skipped: counts.skipped.toLocaleString(),
                            failed: counts.failed.toLocaleString(),
                            warnings: counts.warnings.toLocaleString(),
                        },
                    )}
                </p>

                {running ? (
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
