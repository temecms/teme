<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells search engines to keep away from every response outside production
 * (I-291), so a staging or local copy never competes with the live site.
 * Global, right inside the page cache: a page stored outside production
 * keeps the header, so a hit carries it, and the static-file driver, which
 * gives no file to a page with a header the web server would drop, writes
 * none there. Production's pages never carry it.
 */
final class NoindexOutsideProduction
{
    /** Stricter than any page's own: a copy's links lead nowhere search engines should go. */
    public const string DIRECTIVES = 'noindex, nofollow';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Asked for each response: isProduction() reads the application's
        // environment, which nothing caches.
        if (! app()->isProduction()) {
            $response->headers->set('X-Robots-Tag', self::DIRECTIVES);
        }

        return $response;
    }
}
