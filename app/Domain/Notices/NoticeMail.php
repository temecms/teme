<?php

/**
 * A plain message of a declared kind (Notices::send()), queued: its subject,
 * lines, quote and action drawn into the one layout when it is sent. Everything
 * written by someone else goes through MailText.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Teme\Contracts\Notices\NoticeMessage;

final class NoticeMail extends Notification implements ShouldQueue
{
    use Queueable, SentAsNotice;

    public function __construct(public readonly string $kind, public readonly NoticeMessage $message) {}

    public function noticeKind(): string
    {
        return $this->kind;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->message->subject);

        foreach ($this->message->lines as $line) {
            $mail->line(MailText::inline($line));
        }

        if ($this->message->quote !== null) {
            $mail->line(MailText::quote($this->message->quote));
        }

        if ($this->message->actionLabel !== null && $this->message->actionRoute !== null) {
            // Made as it is sent, from the configured address, never stored (rule 9).
            $mail->action($this->message->actionLabel, rtrim((string) config('app.url'), '/').route($this->message->actionRoute, $this->message->actionParameters, false));
        }

        return NoticeLayout::apply($mail, $this->kind, $notifiable);
    }
}
