<?php

/**
 * Ends a piece of work as failed, with what went wrong, and tells whoever started it in their inbox that retrying carries on (I-1714). A job that has already ended stays as it ended.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs\Operations;

use App\Domain\Inbox\InboxLink;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Jobs\JobAccess;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class FailJob extends BaseOperation
{
    public function __construct(private JobAccess $access, private SendToInbox $inbox) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var FailJobInput $input */
        if (! $this->access->allows(TrackedJob::query()->findOrFail($input->jobId), $input->actorId())) {
            throw new AuthorizationException('Only whoever started a job, or someone who looks after jobs, reports on it.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FailJobInput $input */
        Validator::make(['message' => $input->message], ['message' => ['required', 'string', 'max:500']])->validate();
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var FailJobInput $input */
        $job = TrackedJob::query()->whereKey($input->jobId)->lockForUpdate()->firstOrFail();

        // Under the lock: a job that has ended stays as it ended.
        if ($job->state->ended()) {
            return false;
        }

        $job->update(['state' => JobState::Failed, 'message' => $input->message, 'ended_at' => now()]);

        if ($job->started_by !== null) {
            $this->inbox->execute(new SendToInboxInput(
                recipientId: (string) $job->started_by,
                kind: 'job.failed',
                title: __('Failed: :label', ['label' => $job->label]),
                text: __(':message Retrying carries on from where it stopped.', ['message' => $input->message]),
                link: new InboxLink('tools.jobs.index', [], JobAccess::MANAGE),
                actorId: $input->actorId(),
                owner: $job->owner,
            ));
        }

        return true;
    }
}
