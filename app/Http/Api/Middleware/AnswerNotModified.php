<?php

/**
 * Answers 304 to a GET or HEAD whose If-None-Match matches the answer's
 * ETag (docs/superpowers/plans/2026-11-11-phase3-slice31-delivery-api-first-release.md, D12),
 * whether the page cache served it or it was drawn. Global, just outside the
 * page cache; only API, llms.txt and .md answers carry an ETag.
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

final class AnswerNotModified
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 200 && $response->headers->has('ETag')) {
            // Symfony checks the method and If-None-Match, and empties the body.
            $response->isNotModified($request);
        }

        return $response;
    }
}
