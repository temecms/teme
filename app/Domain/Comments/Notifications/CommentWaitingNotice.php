<?php

/**
 * The moderators' email when a comment waits for approval (E5, I-689): the
 * comment's words, the moderator's reason, a link to the comment in the
 * admin and how many wait; never an IP address, a browser, an email address
 * or a one-click action. Sent at once by SendCommentWaitingEmails, which is
 * itself the queued job, with values it read when it ran.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Notifications;

use App\Domain\Comments\CommentEmails;
use App\Domain\Notices\MailText;
use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CommentWaitingNotice extends Notification
{
    use SentAsNotice;

    public function __construct(
        public readonly string $commentId,
        public readonly string $authorName,
        public readonly string $text,
        public readonly ?string $reason,
        public readonly string $itemTitle,
        public readonly int $waiting,
        public readonly string $site,
    ) {}

    public function noticeKind(): string
    {
        return CommentEmails::WAITING;
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Made as it is sent, from the configured address, never stored (rule 9).
        $review = rtrim((string) config('app.url'), '/').route('comments.index', ['comment' => $this->commentId], false);

        $mail = (new MailMessage)
            // A mail header, never Markdown: the title as typed.
            ->subject(__('A comment is waiting for approval on “:title”', ['title' => $this->itemTitle]))
            ->line(__(':name commented on “:title”, and it’s waiting for your approval.', [
                'name' => MailText::inline($this->authorName),
                'title' => MailText::inline($this->itemTitle),
            ]))
            ->line(MailText::quote($this->text));

        if ($this->reason !== null && trim($this->reason) !== '') {
            $mail->line(MailText::inline($this->reason));
        }

        $mail
            ->action(__('Review the comment'), $review)
            ->line(trans_choice(':count comment is waiting on the posts and pages you look after.|:count comments are waiting on the posts and pages you look after.', $this->waiting));

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable);
    }
}
