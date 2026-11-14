<?php

declare(strict_types=1);

namespace App\Domain\Import\Tracking;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Jobs\Queries\TrackedJobList;
use App\Domain\Support\Security\JobActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * An import run as a tracked job (I-1714): the job each attempt starts, or
 * takes up again, each step the run reaches, and the run's end, as the
 * engine tells it. Tracking never stops an import: whatever goes wrong
 * here is reported, and the run goes on without its job.
 */
final readonly class ImportRunJobs
{
    /** The run type a run's tracked jobs carry. */
    public const string RUN_TYPE = 'import';

    public function __construct(private ImportPhases $phases, private TrackedJobList $jobs) {}

    /**
     * Starts the run's tracked job, or takes up the one its last attempt
     * left, and reports the step the run is on. The engine calls it as an
     * attempt begins, after the run's commit.
     */
    public function begin(ImportRun $run, JobActor $actor): void
    {
        try {
            $this->takeUp($run, $actor);
            $this->progress((string) $run->id, $actor);
        } catch (Throwable $failure) {
            self::tell((string) $run->id, $failure);
        }
    }

    /**
     * Reports the step the run has reached to its live job: the steps done,
     * and "Now: {step}". The engine calls it after each phase's advance.
     */
    public function report(string $runId, JobActor $actor): void
    {
        try {
            $this->progress($runId, $actor);
        } catch (Throwable $failure) {
            self::tell($runId, $failure);
        }
    }

    /**
     * Ends the run's live job with the run: finished, with its counts; or
     * failed, in the run's own stop message. A job that has already ended (a
     * cancel) stays as it ended. Called by FinishImportRun and StopImportRun
     * after their commit, as whoever finished or stopped the run.
     */
    public function ended(string $runId, ?string $actorId): void
    {
        try {
            $run = ImportRun::query()->find($runId);
            $jobId = $this->liveJob($runId);

            if ($run === null || $jobId === null) {
                return;
            }

            if ($run->state === ImportRunState::Finished) {
                app(FinishJob::class)->execute(new FinishJobInput($jobId, $this->summary($run), $actorId));
            } elseif ($run->state === ImportRunState::Stopped) {
                $words = $run->stop_message ?? 'Stopped on request.';
                app(FailJob::class)->execute(new FailJobInput($jobId, Str::limit($words, 490), $actorId));
            }
        } catch (Throwable $failure) {
            self::tell($runId, $failure);
        }
    }

    /**
     * Reports a tracking failure to the log, wrapped: Laravel's handler drops
     * a bare ValidationException or AuthorizationException, which are the
     * usual refusals here.
     */
    private static function tell(string $runId, Throwable $failure): void
    {
        report(new RuntimeException("Teme couldn’t track import run {$runId}.", previous: $failure));
    }

    /**
     * The step the run is on, reported to its live job, the steps before it
     * counted as done.
     */
    private function progress(string $runId, JobActor $actor): void
    {
        $run = ImportRun::query()->find($runId);
        $job = $this->jobs->ofRun(self::RUN_TYPE, $runId)[0] ?? null;

        if ($run === null || $job === null || JobState::from($job['state'])->ended()) {
            return;
        }

        $steps = $this->steps($run->kind);
        $now = array_search($run->phase, array_map(fn (ImportPhase $phase): string => $phase->key(), $steps), true);

        app(ReportJobProgress::class)->execute(new ReportJobProgressInput(
            $job['id'],
            advancedBy: max(0, ($now === false ? 0 : $now) - $job['done']),
            total: count($steps),
            message: $now === false ? null : 'Now: '.$steps[$now]->label(),
            actorId: $actor->userId,
        ));
    }

    /**
     * Returns the ID of the run's newest tracked job while it is still under
     * way, or null when it has none.
     */
    public function liveJob(string $runId): ?string
    {
        $newest = $this->jobs->ofRun(self::RUN_TYPE, $runId)[0] ?? null;

        return $newest !== null && ! JobState::from($newest['state'])->ended() ? $newest['id'] : null;
    }

    /**
     * Returns the phases a run of this kind goes through, in order.
     *
     * @return list<ImportPhase>
     */
    public function steps(ImportRunKind $kind): array
    {
        return array_values(array_filter($this->phases->all(), fn (ImportPhase $phase): bool => $phase->runsIn($kind)));
    }

    /**
     * Returns the job's label: "Import from {host}", or "Catch-up from {host}".
     */
    public function label(ImportRun $run): string
    {
        $address = $run->source->address;
        $host = (string) (parse_url($address, PHP_URL_HOST) ?: $address);

        return $run->kind === ImportRunKind::CatchUp ? "Catch-up from {$host}" : "Import from {$host}";
    }

    /**
     * Returns the run's counts as one sentence, as teme:import's status says them.
     */
    public function summary(ImportRun $run): string
    {
        return sprintf(
            '%s created, %s updated, %s skipped, %s failed, %s with warnings.',
            number_format($run->created_count),
            number_format($run->updated_count),
            number_format($run->skipped_count),
            number_format($run->failed_count),
            number_format($run->warning_count),
        );
    }

    /**
     * The job this attempt reports to: the run's newest while it is under
     * way, or failed and set waiting again; else a new one.
     */
    private function takeUp(ImportRun $run, JobActor $actor): void
    {
        $newest = $this->jobs->ofRun(self::RUN_TYPE, (string) $run->id)[0] ?? null;

        if ($newest !== null && ! JobState::from($newest['state'])->ended()) {
            return;
        }

        if ($newest !== null && $newest['state'] === JobState::Failed->value) {
            try {
                // The kind's retry finds the run already under way, and leaves it so.
                app(RetryJob::class)->execute(new RetryJobInput($newest['id'], $actor->userId));

                return;
            } catch (AuthorizationException|ValidationException) {
                // Someone who may not act on it carries the run on: a job of their own.
            }
        }

        app(StartJob::class)->execute(new StartJobInput(
            kind: ImportJobKind::KEY,
            label: $this->label($run),
            total: count($this->steps($run->kind)),
            runType: self::RUN_TYPE,
            runId: (string) $run->id,
            actorId: $actor->userId,
        ));
    }
}
