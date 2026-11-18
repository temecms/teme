<?php

/**
 * The management API's limit per key: as many requests a minute as the
 * setting allows, 60 unless an administrator changes it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\Manage\Middleware;

use App\Domain\ManageApi\ManageApiSettings;
use App\Http\Api\Manage\ManageRequest;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleManageKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-manage:'.ManageRequest::caller($request)->keyId;
        $limit = ManageApiSettings::perMinute();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => RateLimiter::availableIn($key),
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit - RateLimiter::attempts($key)));

        return $response;
    }
}
