<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Jobs\AdvanceBulkRun;
use App\Domain\Contents\Models\BulkRun;
use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Support\Security\JobActor;
use Illuminate\Validation\ValidationException;

/**
 * Select all matching as a kind of tracked job (I-104): retrying carries a run on from where it stopped, as the person who started it; cancelling leaves nothing to drop, since the next step's first report stops it.
 */
final readonly class BulkRunJobKind implements JobKind
{
    /** The kind's key. */
    public const string KEY = 'content.bulk';

    /** The run type its jobs carry, with the run's ID. */
    public const string RUN_TYPE = 'content-bulk';

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        $run = $job->runId === null ? null : BulkRun::query()->find($job->runId);

        if ($run === null) {
            $words = __('The person who started this no longer has an account, so it can’t carry on.');
            // Back to failed, as before Retry, in these words.
            app(FailJob::class)->execute(new FailJobInput($job->id, $words, $actorId));

            throw ValidationException::withMessages(['job' => $words]);
        }

        // As whoever started it, whoever asked: each item was theirs to change (I-392).
        AdvanceBulkRun::dispatch((string) $run->id, JobActor::person((int) $run->user_id));
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        // Nothing is queued apart from the next step, whose first report answers false for a cancelled job (I-104).
    }
}
