<?php

/**
 * Laravel's guard times only its own password check. Fortify's two-factor
 * step checks the password outside it, and a reset request hashes a token
 * only for real accounts — so these routes are held to one floor, whatever
 * happens inside. SECURITY.md commitment 10.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Symfony\Component\HttpFoundation\Response;

final class EnforceResponseTimeFloor
{
    private const array ROUTES = ['login.store', 'password.email', 'password.update', 'two-factor.login.store'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(...self::ROUTES)) {
            return $next($request);
        }

        /** @var Response */
        return (new Timebox)->call(fn (): Response => $next($request), (int) config('auth.timebox_duration'));
    }
}
