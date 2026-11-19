<?php

/**
 * An import as a kind of tracked job. Retrying carries a stopped run on as
 * whoever asked, which asks for their password (I-1707); when that is
 * refused the job goes back to failed and the refusal goes on to them, in
 * words. Cancelling stops the run as whoever asked, when they may.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Tracking;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\ContinueImportRun;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Support\Security\ElevationRequiredException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class ImportJobKind implements JobKind
{
    /** The kind's key, which is also the run type its jobs carry. */
    public const string KEY = 'import';

    public function __construct(private ImportRunJobs $jobs) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        $run = $job->runId === null ? null : ImportRun::query()->with('source')->find($job->runId);

        if ($run === null) {
            $this->refuse($job, $actorId, 'Teme no longer has this import.');
        }

        // Carried on already: the attempt that carried it on is taking this job up.
        if ($run->state === ImportRunState::Running) {
            return;
        }

        if ($run->state === ImportRunState::Finished) {
            app(FinishJob::class)->execute(new FinishJobInput($job->id, $this->jobs->summary($run), $actorId));

            throw ValidationException::withMessages(['job' => 'This import has finished since, so there’s nothing to carry on.']);
        }

        try {
            app(ContinueImportRun::class)->execute(new ContinueImportRunInput((string) $run->id, $actorId));
        } catch (ValidationException $refused) {
            $this->failAgain($job, $actorId, (string) $refused->validator->errors()->first());

            throw $refused;
        } catch (AuthorizationException) {
            // Someone who may manage jobs but not import: told in words, not a 403.
            $this->refuse($job, $actorId, 'Only someone who may import sites can carry this import on.', (string) $run->stop_message);
        } catch (ElevationRequiredException $refused) {
            $this->failAgain($job, $actorId, (string) $run->stop_message);

            throw $refused;
        }
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        if ($job->runId === null) {
            return;
        }

        try {
            app(StopImportRun::class)->execute(new StopImportRunInput($job->runId, $actorId));
        } catch (ValidationException) {
            // It has stopped or finished already: nothing to stop.
        } catch (AuthorizationException $refused) {
            // The job is cancelled already (CancelJob), and nothing can reopen it; the run goes on.
            report(new RuntimeException("Teme couldn’t stop import run {$job->runId} when its job was cancelled.", previous: $refused));
        }
    }

    /**
     * The job back to failed (in the run's words when given, else these),
     * and these words refused.
     */
    private function refuse(JobRecord $job, ?string $actorId, string $words, ?string $jobWords = null): never
    {
        $this->failAgain($job, $actorId, $jobWords ?? $words);

        throw ValidationException::withMessages(['job' => $words]);
    }

    /**
     * The job back to failed, as it was before Retry, in these words.
     */
    private function failAgain(JobRecord $job, ?string $actorId, string $words): void
    {
        app(FailJob::class)->execute(new FailJobInput($job->id, Str::limit($words === '' ? 'Stopped.' : $words, 490), $actorId));
    }
}
