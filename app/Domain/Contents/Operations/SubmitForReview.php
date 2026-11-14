<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SubmitForReviewInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Hands one revision of an article to an editor for review (decision 5A).
 * The revision is pinned, so the editor reviews what was handed over, not
 * whatever has been typed since. A draft goes into review; a live article
 * stays published while its update waits, because readers still see it.
 */
final class SubmitForReview extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SubmitForReviewInput $input */
        $user = User::find($input->actorId());

        // A trashed article is not found, so it cannot be submitted.
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to submit this content for review.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SubmitForReviewInput $input */
        $content = Content::findOrFail($input->contentId);

        if (! in_array($content->status, ['draft', 'published'], true)) {
            throw ValidationException::withMessages(['content' => __('Only a draft or a published item can be submitted for review.')]);
        }

        $belongs = ContentRevision::query()
            ->whereKey($input->revisionId)
            ->where('content_id', $content->id)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages(['revisionId' => __('That revision does not belong to this content.')]);
        }

        self::ensureNothingPending($content->id);
    }

    /**
     * The new review's ID.
     */
    protected function perform(OperationInput $input): string
    {
        /** @var SubmitForReviewInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);

        // Checked again under the lock: two submissions at once cannot both
        // pass the check made before it.
        self::ensureNothingPending($content->id);

        $review = ContentReview::query()->create([
            'content_id' => $content->id,
            'revision_id' => $input->revisionId,
            'submitted_by' => (int) $input->actorId(),
            'submitted_at' => Date::now(),
        ]);

        if ($content->status === 'draft') {
            $before = ContentState::of($content);
            $content->update(['status' => 'review']);
            ContentCounts::apply($before, ContentState::of($content));
        }

        return (string) $review->id;
    }

    private static function ensureNothingPending(string $contentId): void
    {
        if (ContentReview::query()->where('content_id', $contentId)->whereNull('decided_at')->exists()) {
            throw ValidationException::withMessages(['content' => __('This is already waiting for review.')]);
        }
    }
}
