<?php

/**
 * The management API's outermost middleware, in the API group's place for
 * it: every answer, problems too, is marked no-store, so neither the page
 * cache nor any other keeps one; none carries an ETag or sets a cookie
 * (docs/proposals/management-api.md, Settled 6).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\Manage\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AnswerManageApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->remove('ETag');
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
