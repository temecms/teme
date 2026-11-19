<?php

/**
 * What ApproveReview and ReturnForChanges share: who may decide — whoever
 * may publish the article, as the policy asks its type — that the review is
 * still pending, and the locks, the article first and then the review.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReviewDecisions
{
    public static function authorize(ReviewDecisionInput $input): Content
    {
        $review = ContentReview::query()->findOrFail($input->reviewId);
        $content = Content::findOrFail($review->content_id);
        $user = User::find($input->actorId());

        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to decide on this review.');
        }

        return $content;
    }

    public static function ensurePending(string $reviewId): void
    {
        if (ContentReview::query()->findOrFail($reviewId)->decided_at !== null) {
            throw ValidationException::withMessages(['reviewId' => __('This review has already been decided.')]);
        }
    }

    /**
     * Locks the article, then the review, and checks again that the review
     * is pending, so two editors deciding at once cannot both win.
     *
     * @return array{0: Content, 1: ContentReview}
     */
    public static function lock(string $reviewId): array
    {
        $contentId = (string) ContentReview::query()->whereKey($reviewId)->value('content_id');
        $content = Content::lockForUpdate()->findOrFail($contentId);
        $review = ContentReview::query()->lockForUpdate()->findOrFail($reviewId);

        if ($review->decided_at !== null) {
            throw ValidationException::withMessages(['reviewId' => __('This review has already been decided.')]);
        }

        return [$content, $review];
    }
}
