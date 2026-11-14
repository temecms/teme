<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations;

use App\Domain\Inbox\InboxLink;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Jobs\JobAccess;
use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Ends a waiting or running job as cancelled, tells whoever started it when
 * someone else did, and, after commit, asks its kind to drop what it keeps
 * queued (I-104). What the work did stays done.
 */
final class CancelJob extends BaseOperation
{
    public function __construct(
        private JobAccess $access,
        private JobKinds $kinds,
        private Actors $actors,
        private SendToInbox $inbox,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var CancelJobInput $input */
        if (! $this->access->allows(TrackedJob::query()->findOrFail($input->jobId), $input->actorId())) {
            throw new AuthorizationException('You can’t cancel this job. Only the person who started it, or someone who manages jobs, can.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): JobRecord
    {
        /** @var CancelJobInput $input */
        $job = TrackedJob::query()->whereKey($input->jobId)->lockForUpdate()->firstOrFail();

        // Under the lock, so two people cancelling at once can't both.
        if ($job->state->ended()) {
            throw ValidationException::withMessages(['job' => __('This job has already ended, so there’s nothing to cancel.')]);
        }

        $actorId = $input->actorId();
        $who = $actorId === null ? null : $this->actors->find($actorId);

        $job->update([
            'state' => JobState::Cancelled,
            'message' => $who === null ? __('Cancelled at the server.') : __('Cancelled by :name.', ['name' => $who->name]),
            'ended_at' => now(),
        ]);

        // Someone else's cancel is news to whoever started it (I-221).
        if ($job->started_by !== null && (string) $job->started_by !== $actorId) {
            $this->inbox->execute(new SendToInboxInput(
                recipientId: (string) $job->started_by,
                kind: 'job.cancelled',
                title: __('Cancelled: :label', ['label' => $job->label]),
                text: $who === null ? __('It was cancelled at the server. What it did stays done.') : __(':name cancelled it. What it did stays done.', ['name' => $who->name]),
                link: new InboxLink('tools.jobs.index', [], JobAccess::MANAGE),
                actorId: $actorId,
                owner: $job->owner,
            ));
        }

        $record = JobRecord::of($job);
        $kind = $this->kinds->find($job->kind);

        // Rule 6: the kind drops its queued work only once the cancel has committed.
        if ($kind !== null) {
            DB::afterCommit(fn () => $kind->cancel($record, $actorId));
        }

        return $record;
    }
}
