<?php

declare(strict_types=1);

namespace App\Domain\Comments\Jobs;

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Notifications\CommentApprovedNotice;
use App\Domain\Comments\Queries\CommentRecipients;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Visibility;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Emails an item's author that a comment on it is approved (E6, I-690;
 * 1.8d's D8, D9). Everything is read when it runs: the comment must still be
 * approved, its item published and out of the trash, comments and the email
 * on, and the author must want it. It carries the person whose action
 * approved it, and the author is never emailed about their own comment or
 * their own approval; it calls no Operation, writes nothing and asks for no
 * elevation, so it runs as the queue does, under DeniedElevation.
 */
final class SendCommentApprovedEmail implements ShouldQueue
{
    use Queueable;

    /** It runs once, as SendCommentWaitingEmails does. */
    public int $tries = 1;

    public function __construct(
        public readonly string $commentId,
        public readonly ?string $actorId,
    ) {}

    public function handle(CommentRecipients $recipients, ContentTypeRegistry $types): void
    {
        if (! CommentEmails::sends(CommentEmails::APPROVED)) {
            return;
        }

        $comment = Comment::query()->find($this->commentId);
        $item = $comment === null ? null : Content::query()->find($comment->content_id);

        if ($comment === null || $item === null || $comment->kind !== CommentKind::Comment || $comment->status !== CommentStatus::APPROVED
            || $item->status !== 'published' || $item->visibility !== Visibility::Public || ! $types->has($item->type)) {
            return;
        }

        // Nobody is emailed about their own action (D9): the writer, and whoever approved it.
        $leaveOut = array_values(array_filter([$comment->author_user_id, $this->actorId === null ? null : (int) $this->actorId]));
        $author = $recipients->author($item, $leaveOut, CommentEmails::APPROVED);

        if ($author === null) {
            return;
        }

        $author->notifyNow(new CommentApprovedNotice(
            $comment->id,
            (string) $comment->author_name,
            CommentText::plain($comment->body ?? []),
            (string) $item->title,
            mb_strtolower($types->get($item->type)->singular),
            CommentModeration::allows($author, $item),
        ));
    }
}
