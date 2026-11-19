import { Form } from '@inertiajs/react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import { FigureTiles } from '@/components/tools/figure-tiles';
import type { FigureTile } from '@/components/tools/figure-tiles';
import { PhaseList } from '@/components/tools/phase-list';
import { Button } from '@/components/ui/button';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { Progress } from '@/components/ui/progress';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { JobRow } from '@/types/jobs';
import type { ImportRunView } from '@/types/import';

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
    const waiting = running && job !== null && job.state === 'waiting';
    const at = run.steps.findIndex((step) => step.state === 'now');
    const percent = job?.percent ?? null;
    const left =
        job !== null && job.total !== null
            ? Math.max(0, job.total - job.done)
            : null;
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
        ...(left === null
            ? []
            : [
                  {
                      key: 'left',
                      label: __('Still to do'),
                      value: left,
                      tone: 'neutral' as const,
                  },
              ]),
    ];
    const where = waiting
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
                    className="text-lead font-semibold"
                >
                    {running ? __('Importing') : __('Import stopped')}
                </h2>
                <StatusPill
                    tone={waiting ? 'neutral' : running ? 'info' : 'warn'}
                >
                    {waiting
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
                            tone={running ? 'accent' : 'quiet'}
                            label={__('Progress of the import')}
                        />
                    )}
                </div>

                {!running && run.stopMessage !== null && (
                    <p>{run.stopMessage}</p>
                )}

                <FigureTiles
                    figures={figures}
                    label={__('Counts so far')}
                    testId="import-counts"
                />

                <section
                    aria-labelledby="import-phases-heading"
                    className="grid gap-3"
                >
                    <h3
                        id="import-phases-heading"
                        className="text-lead font-semibold"
                    >
                        {__('Steps of this import')}
                    </h3>
                    <PhaseList
                        label={__('Steps of this import')}
                        phases={run.steps.map((step) => ({
                            key: step.key,
                            label: step.label,
                            state:
                                step.state === 'done'
                                    ? 'done'
                                    : step.state === 'now'
                                      ? running && !waiting
                                          ? 'now'
                                          : running
                                            ? 'next'
                                            : 'stopped'
                                      : 'next',
                            word:
                                step.state === 'done'
                                    ? __('Done')
                                    : step.state === 'now' && !waiting
                                      ? running
                                          ? __('Under way')
                                          : __('Stopped')
                                      : __('Waiting'),
                        }))}
                    />
                </section>

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
