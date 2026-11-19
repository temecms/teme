<?php

/**
 * Runs for every sign-in, including one restored from a remember-me cookie.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth\Listeners;

use App\Http\Admin\Auth\DeviceCookie;
use App\Http\Admin\Auth\SignInThrottle;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;

final class RecordSignIn
{
    public function handle(Login $event): void
    {
        $request = request();

        if ($request->hasSession()) {
            $now = Date::now()->getTimestamp();
            $request->session()->put(['auth.signed_in_at' => $now, 'auth.last_seen_at' => $now]);
        }

        if (! $event->user instanceof CanResetPassword) {
            return;
        }

        $email = $event->user->getEmailForPasswordReset();

        RateLimiter::clear(SignInThrottle::accountIpKey($email, (string) $request->ip()));
        Cookie::queue(DeviceCookie::make($email, $request));
    }
}
