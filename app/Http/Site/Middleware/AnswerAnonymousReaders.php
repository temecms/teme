<?php

/**
 * Answers /_teme/session at once for a browser that carries no signed-in
 * marker (I-163): not signed in, nothing to edit, and no session started or
 * cookie set, so asking costs no more than it must. First in the `reader`
 * group, before any session middleware (bootstrap/app.php).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\SignedInMarker;
use App\Http\Site\SessionController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AnswerAnonymousReaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return SignedInMarker::present($request) ? $next($request) : SessionController::answer(false, null, null);
    }
}
