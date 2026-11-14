import { Form } from '@inertiajs/react';
import { Check } from 'lucide-react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import { JobProgress } from '@/components/jobs/job-progress';
import { Button } from '@/components/ui/button';
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

    return (
        <section
            aria-labelledby="import-progress-heading"
            className="max-w-[640px] space-y-4"
        >
            <h2 id="import-progress-heading" className="text-base font-medium">
                {running ? 'Importing' : 'Stopped'}
            </h2>

            {job !== null && <JobProgress job={job} />}

            {!running && run.stopMessage !== null && (
                <p className="text-[13px]">{run.stopMessage}</p>
            )}

            <ol
                aria-label="Steps of this import"
                className="space-y-1 text-[13px]"
            >
                {run.steps.map((step) => (
                    <li
                        key={step.key}
                        className={
                            step.state === 'now'
                                ? 'flex items-center gap-2 font-medium'
                                : 'text-ink-2 flex items-center gap-2'
                        }
                    >
                        {step.state === 'done' && (
                            <Check aria-hidden="true" className="size-3.5" />
                        )}
                        {step.label}
                        {step.state === 'done' && (
                            <span className="sr-only"> (done)</span>
                        )}
                        {step.state === 'now' && <span> (now)</span>}
                    </li>
                ))}
            </ol>

            <p className="text-[13px] tabular-nums">
                {counts.created.toLocaleString()} created ·{' '}
                {counts.updated.toLocaleString()} updated ·{' '}
                {counts.skipped.toLocaleString()} skipped ·{' '}
                {counts.failed.toLocaleString()} failed ·{' '}
                {counts.warnings.toLocaleString()} with warnings
            </p>

            {running ? (
                <div className="space-y-2">
                    <p className="text-ink-2 text-[13px]">
                        You can leave this page. The import carries on in the
                        background, and the result reaches your inbox.
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
                                Stop the import
                            </Button>
                        )}
                    </Form>
                </div>
            ) : (
                <div className="space-y-2">
                    <Form
                        {...ImportRunController.carryOn.form({ run: run.id })}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                data-test="import-carry-on"
                                disabled={processing}
                            >
                                Carry on
                            </Button>
                        )}
                    </Form>
                    <p className="text-ink-2 text-[13px]">
                        Carrying on picks up where it stopped. Teme asks for
                        your password first.
                    </p>
                </div>
            )}
        </section>
    );
}
