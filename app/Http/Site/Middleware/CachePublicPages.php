<?php

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\RedirectLookup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page cache at the door (I-262): an anonymous reader's request is
 * answered from a stored page before any route, session or maintenance
 * check runs, and a public page drawn for one is stored when the rules
 * allow. One request at a time draws a page, under its lock; the others
 * get its stale copy or wait for it (docs/studies/page-cache.md §3.9).
 * Global, after the proxies are trusted and before maintenance mode
 * (bootstrap/app.php). Switched off, it does nothing at all.
 */
final class CachePublicPages
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PageCache::enabled()) {
            return $next($request);
        }

        // Resolved only when on, so a store misconfigured while it's off can't break a page.
        $cache = app(PageCache::class);
        $now = now()->getTimestamp();
        $bypass = PageCache::bypass($request);
        $key = $bypass === null ? PageKey::of($request) : null;

        if ($key === null) {
            $response = $next($request);

            return PageCache::isPublicPage($request) ? PageCache::bypassed($response, $bypass ?? 'query') : $response;
        }

        $entry = $cache->find($key, $now);

        if ($entry !== null) {
            return self::served($request, $entry, $cache->hit($entry, $now));
        }

        // A HEAD's page is never stored, so nothing waits for it.
        if (! $request->isMethod('GET')) {
            return self::draw($cache, $key, $request, $next);
        }

        $lock = $cache->lock($key);

        if ($lock !== null) {
            try {
                // Looked at again inside the lock: the request that held it
                // may have stored the page since the first look.
                $entry = $cache->find($key, now()->getTimestamp());

                return $entry !== null ? self::served($request, $entry, $cache->hit($entry, now()->getTimestamp())) : self::draw($cache, $key, $request, $next);
            } finally {
                PageCache::release($lock);
            }
        }

        // Another request is drawing it: meanwhile, the copy a changed list
        // set aside, or the page whose life just ended.
        $copy = $cache->staleCopy($key, $now);

        if ($copy !== null) {
            return self::served($request, $copy, $cache->stale($copy, $now));
        }

        // No copy to give, as after a change removed the page: wait for it,
        // and draw it here when it doesn't come.
        $entry = $cache->waitFor($key);

        return $entry !== null ? self::served($request, $entry, $cache->hit($entry, now()->getTimestamp())) : self::draw($cache, $key, $request, $next);
    }

    /**
     * A stored page given as it was stored: a "not found" page that
     * PublicController drew is counted for the 404 log as one drawn is
     * (I-298), with one append to a file and nothing read from the database.
     */
    private static function served(Request $request, PageEntry $entry, Response $response): Response
    {
        if ($entry->status === 404 && in_array(RedirectLookup::NOT_FOUND_TAG, $entry->tags, true)) {
            app(AddressCounts::class)->notFound($request->getPathInfo(), $request->headers->get('referer'));
        }

        // The router's prepareResponse() never sees a global middleware's
        // response: this is what empties a HEAD's body.
        return $response->prepare($request);
    }

    /**
     * Draws the page, noting what it shows as it goes, and stores it when
     * every rule allows.
     */
    private static function draw(PageCache $cache, PageKey $key, Request $request, Closure $next): Response
    {
        $cache->beginDrawing();
        $now = now()->getTimestamp();
        $response = $next($request);

        return PageCache::isPublicPage($request) ? $cache->keep($key, $request, $response, $now) : $response;
    }
}
