<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Seokit;

use Closure;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Teme\Contracts\Sitemaps\SitemapEntry;

/**
 * The fixture's one sitemap; the tests set its pages and entries.
 */
final class NewsSitemap implements PluginSitemap
{
    /** @var list<string> */
    public static array $starts = [];

    /** @var (Closure(?string): mixed)|null */
    public static ?Closure $entries = null;

    public static function reset(): void
    {
        self::$starts = [];
        self::$entries = null;
    }

    /**
     * @return list<string>
     */
    public function starts(): array
    {
        return self::$starts;
    }

    /**
     * @return list<SitemapEntry>
     */
    public function entries(?string $from): array
    {
        /** @var list<SitemapEntry> */
        return self::$entries === null ? [] : (self::$entries)($from);
    }
}
