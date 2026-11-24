<?php

/**
 * Runs an import's phases in their order (I-333), each as one batch of
 * queued jobs: a list phase's pages, or a step's job and its
 * continuations. Every job and the batch's end carry the attempt's token
 * and its actor, so a stopped or superseded attempt's leftovers do nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Jobs\ImportListPage;
use App\Domain\Import\Jobs\RunImportStep;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\AdvanceImportRun;
use App\Domain\Import\Operations\FinishImportRun;
use App\Domain\Import\Operations\Input\AdvanceImportRunInput;
use App\Domain\Import\Operations\Input\FinishImportRunInput;
use App\Domain\Import\Operations\Input\StartMediaPassInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartMediaPass;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Tracking\ImportRunJobs;
use App\Domain\Support\Security\JobActor;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ImportEngine
{
    /** What a refusal's stop message ends with: what to do next. */
    public const string AGAIN = ' Then carry on the import.';

    public function __construct(private ImportPhases $phases, private ImportRuns $runs) {}

    /**
     * Starts the run's phase, the next after it when this release retired
     * it, or its first when that phase is unknown, or finishes the run when
     * no phase is left. Called after StartImportRun or
     * ContinueImportRun commits.
     */
    public function begin(string $runId, string $token, JobActor $actor): void
    {
        $run = ImportRun::query()->find($runId);

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $token) {
            return;
        }

        app(ImportRunJobs::class)->begin($run, $actor);

        $phase = $this->phases->resume($run->phase, $run->kind);

        if ($phase === null) {
            app(FinishImportRun::class)->execute(new FinishImportRunInput($runId, $token, $actor->userId));

            return;
        }

        if ($phase->key() !== $run->phase) {
            $moved = strtolower((string) Str::ulid());

            if (! app(AdvanceImportRun::class)->execute(new AdvanceImportRunInput($runId, $token, $phase->key(), $moved, $actor->userId))) {
                return;
            }

            $token = $moved;
        }

        $this->dispatch($run, $phase, $token, $actor);
    }

    /**
     * A phase's batch has ended: the run stops if a job failed for good,
     * else moves to its next phase, or finishes.
     */
    public function phaseEnded(string $runId, string $token, JobActor $actor, int $failedJobs): void
    {
        $actor->bind();
        $run = ImportRun::query()->find($runId);

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $token) {
            return;
        }

        if ($failedJobs > 0) {
            app(StopImportRun::class)->execute(new StopImportRunInput($runId, $actor->userId, $token, 'page-failed',
                'Part of this import failed three times, or the old site didn’t answer it for about an hour, so it stopped. The application log has the details.'));

            return;
        }

        $next = $this->phases->after($run->phase, $run->kind);

        if ($next === null) {
            $finished = app(FinishImportRun::class)->execute(new FinishImportRunInput($runId, $token, $actor->userId));

            // The articles came first: the pictures follow in a pass of their own, started here (#716).
            if ($finished === true && $run->kind !== ImportRunKind::MediaPass && ($run->choices['media'] ?? null) === 'later') {
                $this->followWithPictures($runId, $actor);
            }

            return;
        }

        $moved = strtolower((string) Str::ulid());

        if (app(AdvanceImportRun::class)->execute(new AdvanceImportRunInput($runId, $token, $next->key(), $moved, $actor->userId))) {
            app(ImportRunJobs::class)->report($runId, $actor);
            $this->dispatch($run, $next, $moved, $actor);
        }
    }

    /**
     * Starts the picture pass that follows a finished import; one that can't
     * start, as when another import of the site began meanwhile, is logged,
     * and the import screen offers it again.
     */
    private function followWithPictures(string $runId, JobActor $actor): void
    {
        try {
            app(StartMediaPass::class)->execute(new StartMediaPassInput($runId, $actor->userId));
        } catch (ValidationException $refused) {
            Log::warning('The picture pass after an import didn’t start.', ['run' => $runId, 'reason' => collect($refused->errors())->flatten()->first()]);
        }
    }

    /**
     * One batch for the phase: a first page for each of its lists, or the
     * step's first job. A phase with nothing to do ends at once.
     */
    private function dispatch(ImportRun $run, ImportPhase $phase, string $token, JobActor $actor): void
    {
        $runId = (string) $run->id;

        $jobs = $phase instanceof ListPhase
            ? array_map(
                fn (int $list): ImportListPage => new ImportListPage($runId, $token, $phase->key(), $list, 0, $actor),
                array_keys($phase->lists($this->runs->context($run, $actor))),
            )
            : [new RunImportStep($runId, $token, $phase->key(), null, $actor)];

        if ($jobs === []) {
            $this->phaseEnded($runId, $token, $actor, 0);

            return;
        }

        Bus::batch($jobs)
            ->name("import:{$runId}:{$phase->key()}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($runId, $token, $actor): void {
                app(ImportEngine::class)->phaseEnded($runId, $token, $actor, $batch->failedJobs);
            })
            ->dispatch();
    }
}
