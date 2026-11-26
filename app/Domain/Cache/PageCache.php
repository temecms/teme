<?php

/**
 * The full-page cache's policy (docs/studies/page-cache.md §3, I-262):
 * which requests it answers or passes by, what it stores and for how long,
 * what it purges, and the headers that say what it did. It serves anonymous
 * readers of public pages, and never lets a failure of its own break a page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Addresses\Paths;
use App\Domain\Cache\Jobs\WarmPages;
use App\Domain\Design\Operations\CreateDesignPreview;
use App\Domain\Hooks\HookDispatcher;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PageResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Cache\PagesPurged;
use Throwable;

final readonly class PageCache
{
    /**
     * Seconds a "not found" page is served: enough to absorb a burst of
     * probes at one address, short enough for an article published there.
     */
    public const int NOT_FOUND_LIFETIME = 10;

    /**
     * Seconds a search's page lives at most (D7): its key comes from the
     * reader, so the pages kept for searches grow with the searches made,
     * which the search limit bounds (1.6h), never with the site.
     */
    public const int SEARCH_LIFETIME = 600;

    /**
     * Seconds a shared cache in front of the site is told it may keep a page
     * (`s-maxage`): a minute at most, however long Teme keeps its own copy,
     * since nothing purges a shared cache Teme doesn't drive. Study R §3.12
     * caps it at the life left; this caps it lower. A CDN a driver purges is
     * told the whole life left, in headers of its own (CdnDriver::describe()).
     */
    public const int SHARED_LIFETIME = 60;

    /**
     * Seconds a page may take to draw and still be stored: purges are
     * remembered this long for the race guard, so a slower page can't be
     * shown fresh (I-264). Generous: a page that takes minutes to draw has
     * bigger problems.
     */
    public const int RENDER_WINDOW = 300;

    /**
     * Seconds one request may hold a page's lock while it draws the page
     * (docs/studies/page-cache.md §3.9): no longer than a page may take to
     * draw and still be stored (RENDER_WINDOW), and as long as PHP lets a
     * request run by default. A request that dies holding it frees it then.
     */
    public const int LOCK_SECONDS = 30;

    /**
     * Seconds a request with no copy to give waits for the page another is
     * drawing, before it draws the page itself: never an error page for a
     * slow draw.
     */
    public const int LOCK_WAIT = 5;

    /** Milliseconds between two looks for the page another request is drawing. */
    public const int WAIT_STEP = 100;

    /** Why a page drawn while one of its tags was purged isn't stored (I-264). */
    public const string PURGED = 'purged while drawn';

    /** Why a page drawn for longer than purges are remembered isn't stored. */
    public const string SLOW = 'drawn too slowly';

    /**
     * The request attribute MarkCacheable sets on a route whose XML or plain
     * text the page cache may store (I-464).
     */
    public const string CACHEABLE = 'teme.page-cache.cacheable';

    /**
     * The request attribute AdsTxtController sets when /ads.txt answers with
     * a permanent redirect that the page cache may store like its text.
     *
     * @since 1.0.0
     */
    public const string REDIRECT_OK = 'teme.page-cache.redirect-ok';

    /**
     * A rate limit's headers: one reader's own count, kept by no stored
     * page and sent on no answer a shared cache may keep.
     */
    private const array LIMIT_HEADERS = ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'];

    /**
     * Headers a stored page never keeps: each response's own, and the
     * cache's, which are set again whenever the page is served.
     */
    private const array PER_REQUEST_HEADERS = [
        'age', 'cache-control', 'connection', 'content-length', 'date', 'expires', 'keep-alive', 'set-cookie', 'transfer-encoding',
        // Search's limit (1.6h) counts one reader's searches, never every reader's.
        ...self::LIMIT_HEADERS,
        'x-teme-cache', 'x-teme-cache-key', 'x-teme-cache-tags', 'x-teme-cache-ttl',
        // The CDN's, set again whenever the page is served (slice 1.7d′).
        'cache-tag', 'cloudflare-cdn-cache-control',
    ];

    /**
     * The most of a page's tags X-Teme-Cache-Tags lists, in characters:
     * an article with its terms, related posts and menus carries a
     * hundred or more, and a proxy may refuse a response whose headers
     * overflow its buffer. The stored page keeps them all.
     */
    private const int TAGS_HEADER_LENGTH = 2000;

    public function __construct(
        private PageStore $store,
        private RenderRecord $record,
        private PurgeTimes $purges,
        private ClockChanges $clock,
        private CdnPurges $cdn,
        private PageWarming $warming,
    ) {}

    /**
     * Whether the page cache is on (`page_cache.enabled`), read for each request.
     */
    public static function enabled(): bool
    {
        return (bool) config('page_cache.enabled');
    }

    /**
     * Seconds a stored page lives at most, `page_cache.lifetime`: a purge
     * removes it when what it shows changes (I-263), and a change that
     * comes by the clock sooner caps it. Never less than a second.
     */
    public static function lifetime(): int
    {
        return max(1, (int) config('page_cache.lifetime'));
    }

    /**
     * Why this request passes the cache by before any key is made, or null
     * when it may be answered from the cache and its page stored: a method
     * other than GET or HEAD; a signed-in reader (I-262); another host than
     * the site's own, since a stored copy for every Host header anyone sends
     * would fill the disk; a design preview, whose parameter the key drops;
     * an address the router answers itself (Paths::RESERVED).
     */
    public static function bypass(Request $request): ?string
    {
        return match (true) {
            ! $request->isMethod('GET') && ! $request->isMethod('HEAD') => 'method',
            SignedInMarker::present($request) => 'signed in',
            $request->getHost() !== self::siteHost() => 'host',
            $request->query->has(CreateDesignPreview::PARAMETER) => 'design preview',
            // The router answers these itself, so no page of the public site is
            // stored there, and nothing is looked up or locked for them.
            in_array(explode('/', ltrim($request->getPathInfo(), '/'))[0], Paths::RESERVED, true) => 'reserved',
            default => null,
        };
    }

    /**
     * Whether a route of the public site or the API answered the request:
     * only their answers are stored.
     */
    public static function isPublicPage(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        $middleware = $route->gatherMiddleware();

        return in_array('public', $middleware, true) || in_array('api', $middleware, true);
    }

    /**
     * Whether the route that answered marked its answer as one the page
     * cache may store though it isn't HTML.
     */
    public static function marked(Request $request): bool
    {
        return $request->attributes->get(self::CACHEABLE) === true;
    }

    /**
     * The stored page for this key while it lives, or null. A store that
     * can't be read is a miss, reported, never an error page.
     */
    public function find(PageKey $key, int $now): ?PageEntry
    {
        try {
            $entry = $this->store->get($key);
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }

        return $entry !== null && ! $entry->expired($now) ? $entry : null;
    }

    /**
     * Takes the lock for drawing this page, from the store that keeps its
     * pages, or null while another request holds it. A lock that can't be
     * reached is reported, and the page is drawn as if it were free: a
     * failure of the cache never breaks a page.
     */
    public function lock(PageKey $key): ?Lock
    {
        try {
            $lock = $this->store->lock($key, self::LOCK_SECONDS);

            return $lock->get() === true ? $lock : null;
        } catch (Throwable $failure) {
            report($failure);

            return new NoLock($key->lockName(), self::LOCK_SECONDS);
        }
    }

    /**
     * Frees a page's lock once its drawing is done. A lock that can't be
     * reached is reported, and frees itself after LOCK_SECONDS.
     */
    public static function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * The copy to give while another request draws this page: the one a
     * change set aside because a list it shows changed, or the page whose
     * life has ended and which nothing has pruned yet; null when there is
     * none, or it can't be read. Never a page a change removed: removed, it
     * has no copy.
     */
    public function staleCopy(PageKey $key, int $now): ?PageEntry
    {
        try {
            $setAside = $this->store->stale($key);

            if ($setAside !== null) {
                return $setAside;
            }

            $page = $this->store->get($key);

            return $page !== null && $page->expired($now) ? $page : null;
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }
    }

    /**
     * Waits up to LOCK_WAIT seconds for the page another request is drawing,
     * looking in the store every WAIT_STEP milliseconds, and returns it once
     * stored, or null when it never comes. It looks at the store, never at
     * the lock, so waiting reads nothing from the database. It counts its
     * looks rather than the clock, so a frozen clock can't keep it waiting.
     */
    public function waitFor(PageKey $key): ?PageEntry
    {
        for ($looks = intdiv(self::LOCK_WAIT * 1000, self::WAIT_STEP); $looks > 0; $looks--) {
            Sleep::for(self::WAIT_STEP)->milliseconds();

            $entry = $this->find($key, now()->getTimestamp());

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Starts the record of a page about to be drawn: when drawing began,
     * and the number of the latest purge (I-264), so a purge noted after
     * it, on whichever server and whatever its clock, keeps the page from
     * being stored. A number that can't be read is reported and taken as
     * 0, which refuses more pages, never fewer.
     */
    public function beginDrawing(): void
    {
        try {
            $latest = $this->purges->latest();
        } catch (Throwable $failure) {
            report($failure);
            $latest = 0;
        }

        $this->record->start($latest);
    }

    /**
     * The response for a stored page: its status, headers and HTML, and how old it is.
     */
    public function hit(PageEntry $entry, int $now): Response
    {
        return $this->described(new PageResponse($entry->body, $entry->status, $entry->headers), 'HIT', $entry, $now, $entry->remaining($now));
    }

    /**
     * The response for a copy given while another request draws its page:
     * marked STALE, with its age, and kept by no shared cache (s-maxage=0).
     */
    public function stale(PageEntry $entry, int $now): Response
    {
        return $this->described(new PageResponse($entry->body, $entry->status, $entry->headers), 'STALE', $entry, $now, 0);
    }

    /**
     * Stores the page drawn for this key when every rule allows, and says
     * on the response what happened: MISS when stored, BYPASS and why when
     * not. A page one of whose tags was purged while it was drawn is never
     * kept (I-264).
     */
    public function keep(PageKey $key, Request $request, Response $response, int $now): Response
    {
        $refusal = StoreRules::refusal($request, $response, $this->record->refusal());

        if ($refusal !== null) {
            return self::bypassed($response, $refusal);
        }

        $status = $response->getStatusCode();

        // Its address's, then everything it drew (I-263): taken before the
        // clock is read, since reading the site's clock records the settings.
        $tags = array_values(array_unique([$key->urlTag(), ...$this->record->tags()]));

        try {
            $entry = new PageEntry(
                $key->url,
                $status,
                self::storedHeaders($response),
                (string) $response->getContent(),
                $now,
                // Its life, or less when something it may show comes by the clock sooner (I-266).
                min($now + self::lifeOf($key, $status), $this->clock->next($now)),
                $tags,
            );
            $stale = $this->staleness($entry);

            if ($stale !== null) {
                return self::bypassed($response, $stale);
            }

            $this->store->put($entry);

            // A purge between that look and the write found nothing of this
            // page's to remove; asked again now, the removal is this one's.
            $stale = $this->staleness($entry);

            if ($stale !== null) {
                $this->store->forget($entry->key);

                return self::bypassed($response, $stale);
            }
        } catch (Throwable $failure) {
            report($failure);

            return self::bypassed($response, 'store failed');
        }

        return $this->described($response, 'MISS', $entry, $now, $entry->remaining($now));
    }

    /**
     * Removes every stored page that carries any of these tags, `*` for all
     * of them, and returns how many; then queues the same purge for the CDN
     * (study R §3.10). It notes the purge first, so a page whose drawing
     * began before now is never stored after it (I-264). Operations' changes
     * go through invalidate(); this removes whatever the tags, for the
     * commands.
     *
     * @param  list<string>  $tags
     */
    public function purge(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        $this->purges->record($tags, now());
        $removed = $this->store->purge($tags);

        // Teme's own copies are gone; the CDN's go next, on the queue.
        $this->cdn->queue($tags);
        $this->cdn->kick();

        return $removed;
    }

    /**
     * What a change does to the stored pages (docs/studies/page-cache.md
     * §3.9, I-266): every page carrying a tag of a thing it changed shows
     * that thing, and is removed, never served again; every other page
     * carrying the tag of a list it changed is set aside, a copy served,
     * marked STALE, only while one request draws it afresh. Every tag is
     * noted first for the race guard (I-264), and queued for the CDN once
     * Teme's own pages are gone (study R §3.10). Returns how many pages went.
     *
     * @param  list<string>  $tags
     */
    public function invalidate(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        $this->purges->record($tags, now());
        $lists = array_values(array_filter($tags, PageTags::isList(...)));
        $things = array_values(array_diff($tags, $lists));

        // What to draw again, read before the change takes it away (study R §3.11).
        $warm = $this->warming->addresses($things, $lists);

        // Things first: a page that shows one is removed, whatever lists it shows.
        $removed = $things === [] ? 0 : $this->store->purge($things);
        $removed += $lists === [] ? 0 : $this->store->markStale($lists, now()->getTimestamp() + self::RENDER_WINDOW);

        // Teme's own copies are gone; the CDN's go next, on the queue, once the
        // pages are drawn again: regenerate first, then purge (study R §3.10).
        $this->cdn->queue($tags);

        if ($warm === []) {
            $this->cdn->kick();
        } else {
            WarmPages::dispatch($warm);
        }

        $this->pagesPurged($tags, $warm);

        return $removed;
    }

    /**
     * Tells plugins which pages this purge touched, once for the purge, and
     * only when one listens. It runs after commit, through PublicChanges::purge(),
     * so a failure is core's to report and never undoes the purge (rule 6).
     *
     * @param  list<string>  $tags
     * @param  list<string>  $addresses
     */
    private function pagesPurged(array $tags, array $addresses): void
    {
        $dispatcher = app(HookDispatcher::class);

        if (! $dispatcher->wants(PagesPurged::class)) {
            return;
        }

        $dispatcher->notify(new PagesPurged($tags, $addresses));
    }

    /**
     * Says on the response that the cache passed it by, and why.
     */
    public static function bypassed(Response $response, string $reason): Response
    {
        $response->headers->set('X-Teme-Cache', "BYPASS ({$reason})");

        return $response;
    }

    /**
     * Seconds a page drawn for this key, answering with this status, lives
     * at most: a "not found" page NOT_FOUND_LIFETIME, a search's page or an
     * API answer with parameters SEARCH_LIFETIME, anything else lifetime(),
     * and none longer than that.
     */
    private static function lifeOf(PageKey $key, int $status): int
    {
        return match (true) {
            $status === 404 => min(self::NOT_FOUND_LIFETIME, self::lifetime()),
            $key->search => min(self::SEARCH_LIFETIME, self::lifetime()),
            default => self::lifetime(),
        };
    }

    /**
     * Why a page may not be stored after all (I-264): drawing it took longer
     * than purges are remembered, or a purge of one of its tags was noted
     * after its drawing began, so it may show what the purge removed. Null
     * when it may. A page whose drawing was never started can't be shown
     * fresh either.
     */
    private function staleness(PageEntry $entry): ?string
    {
        $began = $this->record->began();

        if ($began === null || now()->getTimestamp() - $began->getTimestamp() > self::RENDER_WINDOW) {
            return self::SLOW;
        }

        return $this->purges->since($entry->tags, $this->record->latestPurge()) ? self::PURGED : null;
    }

    /**
     * The site's own host, APP_URL's: the only one a reader may name once
     * trustHosts() checks it (docs/studies/deployment.md §3.6). A health
     * check inside the container may also name localhost, which the cache
     * passes by.
     */
    private static function siteHost(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * A stored page's cache headers: how old it is, and that a shared cache
     * may keep it no longer than it has left, and a minute at most
     * (docs/studies/page-cache.md §3.12, I-491), while browsers ask again
     * each time; a CDN a driver purges, the page's keys and the whole life
     * it has left, in headers of its own (D1); outside production, its key,
     * tags and seconds left too. While a shared cache may keep it, no rate
     * limit's headers, which are one reader's own. It reads nothing from the
     * database.
     */
    private function described(Response $response, string $status, PageEntry $entry, int $now, int $remaining): Response
    {
        // A shared cache keeps it no longer than it has left, and a minute at most (SHARED_LIFETIME).
        $shared = min($remaining, self::SHARED_LIFETIME);
        $response->headers->set('Cache-Control', sprintf('public, max-age=0, s-maxage=%d', $shared));

        // One reader's count of their requests is never passed to everyone a shared cache serves.
        if ($shared > 0) {
            foreach (self::LIMIT_HEADERS as $name) {
                $response->headers->remove($name);
            }
        }
        $response->headers->set('Age', (string) $entry->age($now));
        $response->headers->set('X-Teme-Cache', $status);

        // A copy with no life left, a STALE one, tells the CDN nothing, so it keeps it no time.
        if ($remaining > 0) {
            $this->cdn->driver()?->describe($response, CdnPurges::keys($entry->tags), $remaining);
        }

        if (! app()->isProduction()) {
            $response->headers->set('X-Teme-Cache-Key', $entry->key);
            $response->headers->set('X-Teme-Cache-Tags', self::listed($entry->tags));
            $response->headers->set('X-Teme-Cache-Ttl', (string) $remaining);
        }

        return $response;
    }

    /**
     * The headers a stored page keeps, as the response had them, without
     * its cookies or anything of this request's own.
     *
     * @return array<string, list<string>>
     */
    private static function storedHeaders(Response $response): array
    {
        $kept = [];

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            if (! in_array(strtolower((string) $name), self::PER_REQUEST_HEADERS, true)) {
                $kept[(string) $name] = array_values(array_filter($values, 'is_string'));
            }
        }

        return $kept;
    }

    /**
     * The tags, space-separated, as many whole ones as fit
     * TAGS_HEADER_LENGTH, then how many more there are.
     *
     * @param  list<string>  $tags
     */
    private static function listed(array $tags): string
    {
        $listed = '';

        foreach ($tags as $shown => $tag) {
            $next = $listed === '' ? $tag : $listed.' '.$tag;

            if (strlen($next) > self::TAGS_HEADER_LENGTH) {
                return $listed.' (+'.(count($tags) - $shown).' more)';
            }

            $listed = $next;
        }

        return $listed;
    }
}
