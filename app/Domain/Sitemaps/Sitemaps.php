<?php

/**
 * The sitemaps (I-296): the index of every page of every child the
 * registered providers give, and the page an address names. A page's
 * address carries its first key, which the index read off in one pass, so
 * no page is found by OFFSET; the keys each child's index gave are kept,
 * and a page answers only for one of them, so there are as many addresses
 * as pages.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Cache\PageCache;
use App\Domain\Cache\RenderRecord;
use App\Domain\Seo\WholeSite;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use LogicException;

final class Sitemaps
{
    /** Every sitemap's page-cache tag, a thing's: purging it removes them all. */
    public const string CACHE_TAG = 'sitemap';

    /** At most this many entries in the index, the protocol's limit. */
    public const int MOST_CHILDREN = 50000;

    /** Without the page cache, how long the index reuses a child's keys, in seconds. */
    public const int REUSE = 600;

    /** The application cache's key for a child's keys, before the child's name. */
    public const string KEPT = 'teme:sitemaps:starts:';

    /** A child's name. */
    public const string NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** A page's first key, as its address carries it. */
    public const string KEY = '/^[0-9a-z]{1,26}$/';

    public function __construct(
        private readonly Container $container,
        private readonly RenderRecord $record,
    ) {}

    /**
     * Whether the sitemaps answer: never while the site discourages search
     * engines (I-291).
     */
    public static function on(): bool
    {
        return ! WholeSite::discouraged();
    }

    /**
     * A page's address, absolute, made now from the site's address: the
     * child's first page, or the page that starts at $from.
     */
    public static function address(string $name, ?string $from = null): string
    {
        return rtrim(url('/'), '/').'/sitemap-'.$name.($from === null ? '' : '.'.$from).'.xml';
    }

    /**
     * Every page of every child, absolute, in the providers' order: each
     * child's first page, then one a key, found afresh, or without the page
     * cache those found in the last REUSE seconds.
     *
     * @return list<string>
     */
    public function index(): array
    {
        $this->record->tag(self::CACHE_TAG);
        $addresses = [];

        foreach ($this->children() as $name => $provider) {
            $this->record->tag(...$provider->tags($name));
            $addresses[] = self::address($name);

            foreach ($this->starts($provider, $name, PageCache::enabled()) as $from) {
                $addresses[] = self::address($name, $from);
            }
        }

        return array_slice($addresses, 0, self::MOST_CHILDREN);
    }

    /**
     * The page a file names, `{name}` or `{name}.{first key}`, or null: no
     * such child, a key of the wrong shape, or a key its index didn't give.
     */
    public function page(string $file): ?SitemapPage
    {
        [$name, $from] = array_pad(explode('.', $file, 2), 2, null);
        $provider = $this->children()[$name] ?? null;

        if ($provider === null || ($from !== null && preg_match(self::KEY, $from) !== 1)) {
            return null;
        }

        $this->record->tag(self::CACHE_TAG, ...$provider->tags($name));

        // A key nobody gave is no page, so a made-up address never becomes one more stored copy.
        if ($from !== null && ! in_array($from, $this->starts($provider, $name, false), true)) {
            return null;
        }

        $page = $provider->page($name, $from);
        $this->record->tag(...$page->tags);

        return $page;
    }

    /**
     * Each child's provider, by the child's name, in the providers' order.
     *
     * @return array<string, SitemapProvider>
     */
    private function children(): array
    {
        $children = [];

        foreach ($this->container->tagged(SitemapProvider::TAG) as $provider) {
            if (! $provider instanceof SitemapProvider) {
                throw new LogicException('Registered as a sitemap provider, but '.get_debug_type($provider)." isn't one.");
            }

            foreach ($provider->names() as $name) {
                if (preg_match(self::NAME, $name) !== 1) {
                    throw new LogicException("A sitemap's name [{$name}] isn't one: lower-case letters and digits joined by hyphens.");
                }

                if (isset($children[$name])) {
                    throw new LogicException("Two sitemaps have the name [{$name}].");
                }

                $children[$name] = $provider;
            }
        }

        return $children;
    }

    /**
     * A child's keys: found afresh when $fresh; else those kept, of any age
     * with the page cache and at most REUSE seconds old without it, or
     * found and kept when there are none.
     *
     * @return list<string>
     */
    private function starts(SitemapProvider $provider, string $name, bool $fresh): array
    {
        $kept = Cache::get(self::KEPT.$name);
        $now = now()->getTimestamp();

        if (! $fresh && is_array($kept) && is_int($kept['at'] ?? null) && is_array($kept['starts'] ?? null)
            && (PageCache::enabled() || $kept['at'] > $now - self::REUSE)) {
            return array_values(array_filter($kept['starts'], is_string(...)));
        }

        $starts = $provider->starts($name);
        Cache::put(self::KEPT.$name, ['at' => $now, 'starts' => $starts], now()->addDay());

        return $starts;
    }
}
