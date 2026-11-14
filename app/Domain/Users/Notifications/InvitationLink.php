<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Users\InvitationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * The queued email that invites someone to choose their password. If it cannot
 * be sent, the failure is recorded so the Users screen can show it.
 */
final class InvitationLink extends Notification implements ShouldQueue
{
    use Queueable, SentAsNotice;

    public function __construct(
        public readonly string $token,
        private readonly int $userId,
    ) {}

    public function noticeKind(): string
    {
        return 'users.invitation';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = app(SettingsReader::class)->string('general', 'site_title');
        $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';

        $mail = (new MailMessage)
            ->subject(__('You have been invited to :site', ['site' => $site]))
            ->line(__('An administrator of :site has created an account for you.', ['site' => $site]))
            ->action(__('Choose your password'), InvitationUrl::for($this->token, $email))
            ->line(__('The link works once and expires in 7 days. If you were not expecting this, you can ignore it.'));

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable);
    }

    /**
     * Shown on the person's row in Users, in place of a jobs indicator
     * (owner, D4).
     */
    public function failed(Throwable $exception): void
    {
        app(SecurityLog::class)->record(SecurityEventType::InvitationMailFailed, $this->userId, context: [
            'reason' => Str::limit($exception->getMessage(), 200),
        ]);
    }
}
