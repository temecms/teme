<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\ModeratedComment;
use App\Domain\Comments\Operations\Input\ModerateCommentInput;
use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Operations\OperationInput;

/**
 * Moves a comment between pending, approved, spam and trash, or restores it
 * from the trash or spam to where it was (I-224). Whoever looks after the
 * item's comments may (I-483); a tombstone never moves (I-278), and each move
 * keeps its item's counts in the same transaction. It returns where the
 * comment was and where it went (1.8c's D9).
 */
final class ModerateComment extends BaseOperation
{
    /** Back from the trash or spam to where the comment was, or to pending. */
    public const string RESTORE = 'restore';

    protected function authorize(OperationInput $input): void
    {
        /** @var ModerateCommentInput $input */
        $actor = app(Actors::class)->find($input->actorId());
        $comment = Comment::query()->find($input->commentId);
        $item = $comment === null ? null : Content::withTrashed()->find($comment->content_id);

        if ($actor === null || $item === null || ! CommentModeration::allows($actor, $item)) {
            throw new AuthorizationException('Not permitted to moderate this comment.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ModerateCommentInput $input */
        if ($input->to !== self::RESTORE && ! in_array($input->to, app(CommentStatusRegistry::class)->settable(), true)) {
            throw ValidationException::withMessages(['to' => __('A comment can’t be moved there.')]);
        }
    }

    protected function perform(OperationInput $input): ModeratedComment
    {
        /** @var ModerateCommentInput $input */
        $comment = Comment::query()->lockForUpdate()->findOrFail($input->commentId);
        $before = $comment->status;
        $parked = [CommentStatus::SPAM, CommentStatus::TRASH];

        // A tombstone keeps its place and nothing else (I-278).
        if (! app(CommentStatusRegistry::class)->get($before)->settable) {
            throw ValidationException::withMessages(['commentId' => __('A removed comment can’t be moderated.')]);
        }

        if ($input->to === self::RESTORE && ! in_array($before, $parked, true)) {
            throw ValidationException::withMessages(['to' => __('Only a comment in the trash or spam can be restored.')]);
        }

        $after = $input->to === self::RESTORE ? ($comment->previous_status ?? CommentStatus::PENDING) : $input->to;

        if ($after === $before) {
            return new ModeratedComment($comment->id, $before, $after, $comment->author_name);
        }

        // The trash and spam remember where a comment came from, the first of
        // them it entered, so restoring or "Not spam" puts it back (D10).
        $comment->previous_status = in_array($after, $parked, true)
            ? (in_array($before, $parked, true) ? $comment->previous_status : $before)
            : null;
        $comment->status = $after;
        $comment->status_changed_at = now()->format('Y-m-d H:i:s.uP');
        $comment->save();

        CommentStats::apply($comment, $before, $after);

        // Readers see a change only when the comment enters the thread or
        // leaves it (D5): an arrival sets the thread's pages aside; a
        // departure removes the pages that show it, never served stale (I-276).
        $was = in_array($before, CommentStatus::THREAD, true);
        $is = in_array($after, CommentStatus::THREAD, true);

        if ($is && ! $was) {
            $this->purgeAfterCommit(CommentPurges::arrived($comment->content_id));
        } elseif ($was && ! $is) {
            $this->purgeAfterCommit(CommentPurges::left($comment->content_id, $comment->id));
        }

        // The author's email (1.8d's D8), after commit: never for Restore,
        // which puts back a comment shown before, as WordPress's untrash.
        if ($after === CommentStatus::APPROVED && $input->to !== self::RESTORE) {
            CommentEmails::approved($comment, $input->actorId());
        }

        // The item as ModerateComment::authorize() reads it: a trashed item's comments are still moderated.
        [$commentId, $contentId, $actorId] = [(string) $comment->id, (string) $comment->content_id, $input->actorId()];
        $this->notifyAfterCommit(CommentStatusChanged::class, fn (): CommentStatusChanged => new CommentStatusChanged($commentId, Payloads::item(Content::withTrashed()->findOrFail($contentId)), $before, $after, $actorId));

        return new ModeratedComment($comment->id, $before, $after, $comment->author_name);
    }
}
