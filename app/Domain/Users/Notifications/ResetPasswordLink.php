<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued, so sending adds no time that depends on whether the account
 * exists; and built from APP_URL, so a forged Host header cannot put another
 * domain into a genuine email. Study G, S6 and S7.
 */
final class ResetPasswordLink extends ResetPassword implements ShouldQueue
{
    use Queueable, SentAsNotice;

    public function __construct(string $token, private readonly ?int $userId = null)
    {
        parent::__construct($token);
    }

    public function noticeKind(): string
    {
        return 'users.password_reset';
    }

    /**
     * Laravel's own reset words, in the one layout.
     */
    public function toMail($notifiable): MailMessage
    {
        return NoticeLayout::apply(parent::toMail($notifiable), $this->noticeKind(), $notifiable);
    }

    /**
     * The queue calls this when the mail could not be sent. A reset that
     * silently fails looks like a broken product. Study G, S14.
     */
    public function failed(Throwable $exception): void
    {
        app(SecurityLog::class)->record(SecurityEventType::ResetMailFailed, $this->userId, context: [
            'reason' => Str::limit($exception->getMessage(), 200),
        ]);
    }

    protected function resetUrl($notifiable): string
    {
        return rtrim((string) config('app.url'), '/').route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);
    }
}
