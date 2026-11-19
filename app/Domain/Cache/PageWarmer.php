<?php

/**
 * Draws one public page as a reader's request would, through the whole
 * application in this process, so the page cache stores it if it may:
 * warming never asks over HTTP, where a CDN in front would answer with its
 * own copy (D7). Each page gets a fresh scope, as the queue worker gives
 * each job, so nothing one page read reaches the next.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Support\Security\Elevation;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

final readonly class PageWarmer
{
    /**
     * Draws the page at this address, and returns what X-Teme-Cache said:
     * MISS when it was drawn and stored, HIT when it was stored already,
     * STALE or BYPASS and why otherwise.
     */
    public function warm(string $url): string
    {
        $app = app();
        // The command's own role, which the fresh scope forgets: teme:deploy warms, then records itself as the operator.
        $elevation = $app->make(Elevation::class);
        // Cache::memo()'s settings among them: another process may have changed them.
        $app->forgetScopedInstances();
        $previous = $app->bound('request') ? $app->make('request') : null;
        $kernel = $app->make(HttpKernel::class);
        $request = Request::create($url);

        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return (string) $response->headers->get('X-Teme-Cache', '');
        } finally {
            // The process's own request and role again, for whatever runs after. The page itself was drawn as a visitor's.
            if ($previous instanceof Request) {
                $app->instance('request', $previous);
            }

            $app->instance(Elevation::class, $elevation);
        }
    }
}
