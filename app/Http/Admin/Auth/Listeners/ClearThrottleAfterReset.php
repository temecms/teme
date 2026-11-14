<?php

declare(strict_types=1);

namespace App\Http\Admin\Auth\Listeners;

use App\Http\Admin\Auth\SignInThrottle;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Whoever holds the account's mailbox is its owner; the reset path always
 * stays open, and afterwards strangers' failures no longer count against it.
 */
final class ClearThrottleAfterReset
{
    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof CanResetPassword) {
            RateLimiter::clear(SignInThrottle::accountKey($event->user->getEmailForPasswordReset()));
        }
    }
}
