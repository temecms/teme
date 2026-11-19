<?php

/**
 * Makes every answer to a policy report the same bare 204 (I-3375): outside
 * the rate limiter, so it removes the headers the limiter adds, which would
 * tell a sender how much of the site's budget is left, and gives an
 * accepted, a dropped and a limited report the same Cache-Control.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BareReportAnswer
{
    /** What the limiter adds, and what says when to come back. */
    private const array REVEALING = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Retry-After'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::REVEALING as $header) {
            $response->headers->remove($header);
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
