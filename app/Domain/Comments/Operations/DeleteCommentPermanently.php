<?php

/**
 * Deletes a comment in the trash or spam for good (I-278, D3). One with any
 * replies becomes a tombstone that keeps its place in the thread, its words
 * and its writer's details erased; one with none is deleted, with every
 * tombstone above it left holding nothing. Whoever looks after the item's
 * comments may, and the operator's purge of the trash; a cut-off leaves alone a
 * comment moved since (1.8c′).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\CommentActionInput;
use App\Domain\Contents\Models\Content;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class DeleteCommentPermanently extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var CommentActionInput $input */
        // The operator's scheduled purge names no one (1.8c′'s D8), as
        // DeleteContentPermanently's does; anyone else names a person.
        if ($input->actorId() === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to delete this comment.');
        }

        $actor = app(Actors::class)->find($input->actorId());
        $comment = Comment::query()->find($input->commentId);
        $item = $comment === null ? null : Content::withTrashed()->find($comment->content_id);

        if ($actor === null || $item === null || ! CommentModeration::allows($actor, $item)) {
            throw new AuthorizationException('Not permitted to delete this comment.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): string
    {
        /** @var CommentActionInput $input */
        $comment = Comment::query()->lockForUpdate()->findOrFail($input->commentId);
        $before = $comment->status;

        if (! app(CommentStatusRegistry::class)->get($before)->deletable) {
            throw ValidationException::withMessages(['commentId' => __('Move a comment to the trash or spam before deleting it for good.')]);
        }

        // Emptying's and the purge's cut-off, read under the lock: a comment
        // moved since, even back where it was, is someone else's to decide.
        if ($input->notChangedAfter !== null && CarbonImmutable::parse($comment->status_changed_at)->greaterThan($input->notChangedAfter)) {
            throw ValidationException::withMessages(['commentId' => __('The comment has changed since you opened the list.')]);
        }

        if (self::hasReplies($comment->id)) {
            $comment->forceFill([
                'status' => CommentStatus::REMOVED,
                'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
                'previous_status' => null,
                'author_name' => null,
                'author_email' => null,
                'author_url' => null,
                'author_user_id' => null,
                'body' => null,
                'commenter_token_hash' => null,
                'ip' => null,
                'user_agent' => null,
                'moderation' => null,
            ])->save();

            CommentStats::apply($comment, $before, CommentStatus::REMOVED);

            // A place appears where a comment in the trash or spam showed nothing (D5).
            $this->purgeAfterCommit(CommentPurges::arrived($comment->content_id));

            return CommentStatus::REMOVED;
        }

        $parentId = $comment->parent_id;
        $comment->delete();
        CommentStats::apply($comment, $before, null);

        $gone = [];

        // A tombstone left with nothing beneath it holds no place: up the
        // thread, each locked in turn, a child's lock always taken first.
        while ($parentId !== null) {
            $parent = Comment::query()->lockForUpdate()->find($parentId);

            if ($parent === null || $parent->status !== CommentStatus::REMOVED || self::hasReplies($parent->id)) {
                break;
            }

            $parentId = $parent->parent_id;
            $parent->delete();

            // A tombstone held a conversation's place when it started one (D2).
            CommentStats::apply($parent, CommentStatus::REMOVED, null);
            $gone[] = $parent->id;
        }

        // A tombstone that went was shown; the comment itself, in the trash
        // or spam, never was (D5).
        if ($gone !== []) {
            $this->purgeAfterCommit(CommentPurges::left($comment->content_id, ...$gone));
        }

        return 'deleted';
    }

    private static function hasReplies(string $id): bool
    {
        return Comment::query()->where('parent_id', $id)->exists();
    }
}
