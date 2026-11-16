<?php

declare(strict_types=1);

namespace App\Http\Api\Manage\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The management API's limit per address, asked before a key is read:
 * an address that has failed to sign in 30 times in a minute is answered
 * rate_limited until the minute ends. AuthenticateApiKey counts each
 * failure; a key that signs in is limited per key instead.
 */
final class ThrottleManageAddress
{
    /** Failed sign-ins a minute from one address. */
    public const int PER_MINUTE = 30;

    /**
     * The limiter's key for a request's address.
     */
    public static function key(Request $request): string
    {
        return 'api-manage-address:'.$request->ip();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = self::key($request);

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => self::PER_MINUTE,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        return $next($request);
    }
}
