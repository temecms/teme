<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Cancels an article's pending schedule. The row is kept, marked cancelled —
 * a cancellation is not a failure, and the history reads who scheduled what —
 * and a scheduled draft becomes a draft again. A live article stays live.
 */
final class CancelScheduledPublication extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to publish this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        $pending = ScheduledPublication::query()
            ->where('content_id', $input->contentId)
            ->whereNull('completed_at')->whereNull('failed_at')->whereNull('cancelled_at')
            ->exists();

        if (! $pending) {
            throw ValidationException::withMessages(['content' => __('Nothing is scheduled for this item.')]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ContentActionInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $before = ContentState::of($content);

        ScheduledPublication::cancelPendingFor($content->id, 'cancelled');

        $content->update([
            'scheduled_for' => null,
            'status' => $content->status === 'scheduled' ? 'draft' : $content->status,
        ]);

        ContentCounts::apply($before, ContentState::of($content));

        return null;
    }
}
