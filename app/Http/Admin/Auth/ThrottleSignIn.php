<?php

/**
 * The first step of Fortify's sign-in pipeline: refuse before any password is
 * hashed. Failures are counted by RecordFailedSignIn, from Laravel's Failed
 * event — Fortify's pipeline renders exceptions where they happen, so a step
 * cannot catch the failures of the steps after it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use Closure;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

final class ThrottleSignIn
{
    /**
     * The session key holding when sign-in opens again, for the sign-in page's countdown.
     *
     * @since 0.8.0-alpha.1
     */
    public const string RETRY_AT = 'teme.sign_in_retry_at';

    public function __construct(private readonly SignInThrottle $throttle) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $email = (string) $request->input(Fortify::username(), '');
        $limits = $this->throttle->limitsFor($email, (string) $request->ip(), DeviceCookie::recognises($request, $email));

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                event(new Lockout($request));

                $seconds = RateLimiter::availableIn($limit['key']);

                // The countdown on the sign-in page (docs/proposals/sign-in-pages.md): the moment the
                // message's own number runs out, never more than the refusal already says.
                $request->session()->flash(self::RETRY_AT, Date::now()->getTimestamp() + $seconds);

                throw ValidationException::withMessages([
                    Fortify::username() => [__('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])],
                ])->status(429);
            }
        }

        return $next($request);
    }
}
