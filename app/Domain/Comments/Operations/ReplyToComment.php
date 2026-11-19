<?php

/**
 * A reply written in the admin by whoever looks after the item (I-224, C6;
 * 1.8c′'s D11): approved at once, placed as the thread places replies,
 * approving through ModerateComment the comment it answers when that one
 * waits, and recording no IP address, browser or key. It answers a comment,
 * never a ping, that is approved or waiting, on any item not in the trash.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\CommentDocument;
use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentPlace;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\ModerateCommentInput;
use App\Domain\Comments\Operations\Input\ReplyToCommentInput;
use App\Domain\Comments\PostedReply;
use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\Payloads;
use App\Domain\Settings\Modules;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Operations\OperationInput;

final class ReplyToComment extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var ReplyToCommentInput $input */
        $actor = app(Actors::class)->find($input->actorId());
        $comment = Comment::query()->find($input->commentId);
        $item = $comment === null ? null : Content::withTrashed()->find($comment->content_id);

        if ($actor === null || $item === null || ! CommentModeration::allows($actor, $item)) {
            throw new AuthorizationException('Not permitted to reply to this comment.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReplyToCommentInput $input */
        if (! Modules::on(Modules::COMMENTS)) {
            throw ValidationException::withMessages(['commentId' => __('Comments are switched off.')]);
        }

        $text = self::text($input->text);

        Validator::make(
            ['text' => $text],
            ['text' => ['required', 'string', 'max:5000']],
            // The moderator's words, not the form's "your comment" (question 1).
            [
                'text.required' => __('Write your reply.'),
                'text.max' => __('Shorten your reply to 5,000 characters or fewer. It has :count.', ['count' => number_format(mb_strlen($text))]),
            ],
        )->validate();

        CommentDocument::validate(CommentText::toDocument($text));
    }

    protected function perform(OperationInput $input): PostedReply
    {
        /** @var ReplyToCommentInput $input */
        $answered = Comment::query()->lockForUpdate()->findOrFail($input->commentId);
        $item = Content::query()->find($answered->content_id);

        if ($item === null) {
            throw ValidationException::withMessages(['commentId' => __('Restore its post or page before replying to its comments.')]);
        }

        if ($answered->kind !== CommentKind::Comment) {
            throw ValidationException::withMessages(['commentId' => __('Pingbacks and trackbacks take no replies.')]);
        }

        if (! in_array($answered->status, [CommentStatus::PENDING, CommentStatus::APPROVED], true)) {
            throw ValidationException::withMessages(['commentId' => __('Only a comment that’s approved or waiting can be answered.')]);
        }

        // Replying approves the comment it answers (I-224), through the
        // Operation that moves comments, with its counts and its purge.
        $approvedAnswered = $answered->status === CommentStatus::PENDING;

        if ($approvedAnswered) {
            app(ModerateComment::class)->execute(new ModerateCommentInput($answered->id, CommentStatus::APPROVED, $input->actorId()));
            $answered->refresh();
        }

        $actor = app(Actors::class)->findOrFail($input->actorId());
        [$parentId, $parentPath, $depth] = CommentPlace::under($answered, DiscussionSettings::current()->threadDepth);

        // From the clock, so the thread's path order is the order of writing (1.8a's D16).
        $id = strtolower((string) Str::ulid(now()));

        $reply = Comment::query()->create([
            'id' => $id,
            'content_id' => $item->id,
            'parent_id' => $parentId,
            'path' => $parentPath.$id.'/',
            'depth' => $depth,
            'kind' => CommentKind::Comment,
            'status' => CommentStatus::APPROVED,
            'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
            'author_name' => (string) $actor->name,
            'author_email' => mb_strtolower((string) $actor->email),
            'author_user_id' => $actor->id,
            'body' => CommentText::toDocument(self::text($input->text)),
            // Written in the admin (C6): its writer's account identifies it,
            // so no IP address, browser or key, and the drawer says why.
            'moderation' => ['by' => 'Teme', 'status' => CommentStatus::APPROVED, 'reason' => 'Approved at once: whoever looks after this item’s comments.', 'signals' => ['rule' => 'looks-after', 'written' => 'admin']],
        ]);

        CommentStats::apply($reply, null, CommentStatus::APPROVED);

        // The reply is approved at once, so its item's author hears of it
        // (1.8d's D8); the comment it approves told its own through ModerateComment.
        CommentEmails::approved($reply, $input->actorId());

        $this->purgeAfterCommit(CommentPurges::arrived($item->id));

        $actorId = $input->actorId();
        $this->notifyAfterCommit(CommentStatusChanged::class, fn (): CommentStatusChanged => new CommentStatusChanged($id, Payloads::item($item), null, CommentStatus::APPROVED, $actorId));

        return new PostedReply($reply->id, $approvedAnswered, $answered->author_name);
    }

    /**
     * The words as typed, each line break `\n`, trimmed.
     */
    private static function text(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
