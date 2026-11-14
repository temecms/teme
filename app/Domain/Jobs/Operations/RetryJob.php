<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations;

use App\Domain\Jobs\JobAccess;
use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Sets a failed job waiting again and, after commit, asks its kind to queue
 * the work from where it stopped (I-1714). Only a failed job whose kind is
 * installed can be retried.
 */
final class RetryJob extends BaseOperation
{
    public function __construct(private JobAccess $access, private JobKinds $kinds) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RetryJobInput $input */
        if (! $this->access->allows(TrackedJob::query()->findOrFail($input->jobId), $input->actorId())) {
            throw new AuthorizationException('Only whoever started a job, or someone who looks after jobs, retries it.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): JobRecord
    {
        /** @var RetryJobInput $input */
        $job = TrackedJob::query()->whereKey($input->jobId)->lockForUpdate()->firstOrFail();

        // Under the lock, so Retry pressed twice retries once.
        if ($job->state !== JobState::Failed) {
            throw ValidationException::withMessages(['job' => __('This job isn’t failed, so there’s nothing to retry.')]);
        }

        $kind = $this->kinds->find($job->kind);

        if ($kind === null) {
            throw ValidationException::withMessages(['job' => __('Teme can’t retry this job, because what runs it is no longer installed.')]);
        }

        $job->update(['state' => JobState::Waiting, 'message' => __('Retrying from where it stopped.'), 'ended_at' => null]);

        $record = JobRecord::of($job);
        $actorId = $input->actorId();

        // Rule 6: the work is queued again only once the job says it's waiting.
        DB::afterCommit(fn () => $kind->retry($record, $actorId));

        return $record;
    }
}
