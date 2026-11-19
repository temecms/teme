<?php

/**
 * Tells browsers to reach the site over https alone (I-380): in production
 * at an https APP_URL, every answer to an https request carries
 * Strict-Transport-Security with TEME_HSTS's value. Outermost in the global
 * stack, so stored pages, maintenance mode's answer and errors carry it too.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Deployment\SecureTransport;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StrictTransportSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Asked on the way out: by then the trusted proxies have said
        // whether the reader's request came over https.
        $value = SecureTransport::hsts(app()->isProduction(), (string) config('app.url'), (string) config('teme.deployment.hsts'));

        if ($value !== null && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
