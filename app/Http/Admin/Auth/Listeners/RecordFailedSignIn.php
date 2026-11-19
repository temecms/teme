<?php

/**
 * Counts a failed sign-in against the sign-in limits. It listens for Laravel's
 * `Failed` event and decides nothing itself; `ThrottleSignIn` does the
 * refusing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth\Listeners;

use App\Http\Admin\Auth\DeviceCookie;
use App\Http\Admin\Auth\SignInThrottle;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Fortify;

final class RecordFailedSignIn
{
    public function __construct(private readonly SignInThrottle $throttle) {}

    public function handle(Failed $event): void
    {
        $request = request();
        $email = (string) ($event->credentials[Fortify::username()] ?? '');

        foreach ($this->throttle->limitsFor($email, (string) $request->ip(), DeviceCookie::recognises($request, $email)) as $limit) {
            RateLimiter::hit($limit['key'], $limit['decay']);
        }
    }
}
