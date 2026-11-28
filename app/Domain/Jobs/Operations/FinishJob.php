<?php

/**
 * Ends a piece of work as finished, with its closing words, and tells whoever started it in their inbox (I-104). A job that has already ended stays as it ended.
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
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class FinishJob extends BaseOperation
{
    public function __construct(private JobAccess $access, private SendToInbox $inbox) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var FinishJobInput $input */
        if (! $this->access->allows(TrackedJob::query()->findOrFail($input->jobId), $input->actorId())) {
            throw new AuthorizationException('Only whoever started a job, or someone who looks after jobs, reports on it.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FinishJobInput $input */
        Validator::make(['message' => $input->message], ['message' => ['required', 'string', 'max:500']])->validate();
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var FinishJobInput $input */
        $job = TrackedJob::query()->whereKey($input->jobId)->lockForUpdate()->firstOrFail();

        // Under the lock: a job that has ended stays as it ended.
        if ($job->state->ended()) {
            return false;
        }

        $job->update(['state' => JobState::Finished, 'done' => $job->total ?? $job->done, 'message' => $input->message, 'ended_at' => now()]);

        if ($input->notify && $job->started_by !== null) {
            $this->inbox->execute(new SendToInboxInput(
                recipientId: (string) $job->started_by,
                kind: 'job.finished',
                title: __('Finished: :label', ['label' => $job->label]),
                text: $input->message,
                link: new InboxLink('tools.jobs.index', [], JobAccess::MANAGE),
                actorId: $input->actorId(),
                owner: $job->owner,
            ));
        }

        return true;
    }
}
