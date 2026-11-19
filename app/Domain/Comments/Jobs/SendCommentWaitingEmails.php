<?php

/**
 * Emails whoever looks after an item's comments that one waits for approval
 * (E5, I-689; 1.8d's D7, D9). Everything is read when it runs, never when it
 * was queued: the comment must still wait, its item be out of the trash,
 * comments and the email be on, and only people who look after the item now
 * receive it. It carries the person whose action queued it, who is never
 * emailed about their own comment; it calls no Operation, writes nothing and
 * asks for no elevation, so it runs as the queue does, under DeniedElevation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Jobs;

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Notifications\CommentWaitingNotice;
use App\Domain\Comments\Queries\CommentRecipients;
use App\Domain\Comments\Queries\CommentsWaiting;
use App\Domain\Contents\Models\Content;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Teme\Contracts\Settings\SettingsReader;

final class SendCommentWaitingEmails implements ShouldQueue
{
    use Queueable;

    /** A job that failed after some recipients would email them again: it runs once. */
    public int $tries = 1;

    public function __construct(
        public readonly string $commentId,
        public readonly ?string $actorId,
    ) {}

    public function handle(CommentRecipients $recipients, CommentsWaiting $waiting, SettingsReader $settings): void
    {
        if (! CommentEmails::sends(CommentEmails::WAITING)) {
            return;
        }

        $comment = Comment::query()->find($this->commentId);
        $item = $comment === null ? null : Content::query()->find($comment->content_id);

        if ($comment === null || $item === null || $comment->kind !== CommentKind::Comment || $comment->status !== CommentStatus::PENDING) {
            return;
        }

        // Nobody is emailed about their own action (D9): the writer, and whoever queued it.
        $leaveOut = array_values(array_filter([$comment->author_user_id, $this->actorId === null ? null : (int) $this->actorId]));
        $reason = is_array($comment->moderation) && is_string($comment->moderation['reason'] ?? null) ? $comment->moderation['reason'] : null;
        $site = $settings->string('general', 'site_title');

        foreach ($recipients->moderators($item, $leaveOut, CommentEmails::WAITING) as $person) {
            $person->notifyNow(new CommentWaitingNotice(
                $comment->id,
                (string) $comment->author_name,
                CommentText::plain($comment->body ?? []),
                $reason,
                (string) $item->title,
                $waiting->countFor($person),
                $site,
            ));
        }
    }
}
