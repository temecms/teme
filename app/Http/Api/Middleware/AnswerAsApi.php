<?php

/**
 * The API group's outermost middleware: every answer is marked nosniff and
 * noindex, a 200 gets a strong ETag of its body, and no answer sets a
 * cookie (docs/superpowers/plans/2026-11-11-phase3-slice31-delivery-api-first-release.md, D12, D15).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AnswerAsApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');
        // None is expected; an answer that set one would differ by reader.
        $response->headers->remove('Set-Cookie');

        if ($response->getStatusCode() === 200) {
            $response->setEtag(substr(hash('sha256', (string) $response->getContent()), 0, 32));
        }

        return $response;
    }
}
