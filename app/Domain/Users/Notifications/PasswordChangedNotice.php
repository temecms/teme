<?php

/**
 * The queued email telling someone their password was just changed, with a way
 * to reset it if it was not them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Teme\Contracts\Settings\SettingsReader;

final class PasswordChangedNotice extends Notification implements ShouldQueue
{
    use Queueable, SentAsNotice;

    public function noticeKind(): string
    {
        return 'users.password_changed';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(SettingsReader::class)->string('general', 'site_title');

        $mail = (new MailMessage)
            ->subject(__('Your password was changed'))
            ->line(__('The password for your account on :site was just changed, and every other session was signed out.', ['site' => $site]))
            ->line(__('If this was not you, reset your password now and tell your site\'s administrator.'))
            ->action(__('Reset password'), rtrim((string) config('app.url'), '/').route('password.request', [], false));

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable);
    }
}
