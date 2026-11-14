<?php

declare(strict_types=1);

namespace App\Domain\Security\Listeners;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

/**
 * Laravel's and Fortify's authentication events, into the security log.
 * Fortify's own writes are I-100's exception; this is its second safeguard.
 */
final class RecordSecurityEvents
{
    public function __construct(private readonly SecurityLog $log) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
            RecoveryCodesGenerated::class => 'onRecoveryCodesGenerated',
            RecoveryCodeReplaced::class => 'onRecoveryCodeUsed',
        ];
    }

    public function onLogin(Login $event): void
    {
        $this->log->record(SecurityEventType::SignInSucceeded, $event->user->getAuthIdentifier());
    }

    public function onFailed(Failed $event): void
    {
        $userId = $event->user?->getAuthIdentifier();
        $email = $userId === null ? (string) ($event->credentials[Fortify::username()] ?? '') : null;

        $this->log->record(SecurityEventType::SignInFailed, $userId, $email);
    }

    public function onLockout(Lockout $event): void
    {
        $this->log->record(SecurityEventType::SignInThrottled, null, (string) $event->request->input(Fortify::username(), ''));
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->log->record(SecurityEventType::PasswordReset, $event->user->getAuthIdentifier());
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->log->record(SecurityEventType::TwoFactorEnabled, $event->user->getAuthIdentifier());
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->log->record(SecurityEventType::TwoFactorDisabled, $event->user->getAuthIdentifier());
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->log->record(SecurityEventType::TwoFactorFailed, $event->user->getAuthIdentifier());
    }

    public function onRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->log->record(SecurityEventType::RecoveryCodesGenerated, $event->user->getAuthIdentifier());
    }

    public function onRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        $this->log->record(SecurityEventType::RecoveryCodeUsed, $event->user->getAuthIdentifier());
    }
}
