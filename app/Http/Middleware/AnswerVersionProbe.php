<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Updates\Updater\ProbeSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the updater which Teme answers /up (I-3326), when it asks with a header signed by the app key
 * within the last minute. Everyone else gets /up as it always was, so the version stays private.
 */
final class AnswerVersionProbe
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->path() !== 'up' || ! ProbeSignature::valid((string) $request->headers->get(ProbeSignature::HEADER, ''))) {
            return $response;
        }

        $response->headers->set('X-Teme-Version', (string) config('teme.version'));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
