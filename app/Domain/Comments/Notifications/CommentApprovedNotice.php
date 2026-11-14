<?php

declare(strict_types=1);

namespace App\Domain\Comments\Notifications;

use App\Domain\Comments\CommentEmails;
use App\Domain\Notices\MailText;
use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The author's email when a comment on their item is approved (E6, I-690):
 * the comment's words, a link to read it on the site, and, for an author who
 * looks after the item, a link to it in Comments; never an IP address, a
 * browser or an email address. Sent at once by SendCommentApprovedEmail,
 * which is itself the queued job.
 */
final class CommentApprovedNotice extends Notification
{
    use SentAsNotice;

    public function __construct(
        public readonly string $commentId,
        public readonly string $authorName,
        public readonly string $text,
        public readonly string $itemTitle,
        public readonly string $itemWord,
        public readonly bool $looksAfter,
    ) {}

    public function noticeKind(): string
    {
        return CommentEmails::APPROVED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Made as it is sent, from the configured address, never stored (rule 9).
        $site = rtrim((string) config('app.url'), '/');

        $mail = (new MailMessage)
            ->subject(__('New comment on “:title”', ['title' => $this->itemTitle]))
            ->line(__(':name commented on your :word “:title”.', [
                'name' => MailText::inline($this->authorName),
                'word' => $this->itemWord,
                'title' => MailText::inline($this->itemTitle),
            ]))
            ->line(MailText::quote($this->text))
            ->action(__('Read it on the site'), $site.route('teme.comments.show', ['comment' => $this->commentId], false));

        if ($this->looksAfter) {
            $mail->line(__('You can also reply to it or moderate it in [Comments](:url).', [
                'url' => $site.route('comments.index', ['comment' => $this->commentId], false),
            ]));
        }

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable, replace: ['word' => $this->itemWord]);
    }
}
