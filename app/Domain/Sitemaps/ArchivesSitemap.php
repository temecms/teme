<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\PageTags;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;

/**
 * The type archive pages as a child of their own (I-3304): each shown, not
 * hidden by its row, and listing a live item, at its address, dated by its
 * type's newest item; and, for each other shown language (I-3360), its home
 * and its type archive pages that list a live item in it.
 */
final readonly class ArchivesSitemap implements SitemapProvider
{
    public const string NAME = 'archives';

    public function __construct(private ListedItems $items, private SiteLanguages $languages) {}

    public function names(): array
    {
        return $this->entries() === [] ? [] : [self::NAME];
    }

    public function starts(string $name): array
    {
        return [];
    }

    public function page(string $name, ?string $from): SitemapPage
    {
        return new SitemapPage($this->entries());
    }

    public function tags(string $name): array
    {
        $tags = [];

        foreach (TypeArchives::types() as $type) {
            $tags[] = PageTags::listOf($type);
            $path = TypeArchives::path($type);

            if ($path !== null) {
                $tags[] = PageTags::url($path);
            }
        }

        return $tags;
    }

    /**
     * The archive pages this child lists.
     *
     * @return list<SitemapAddress>
     */
    private function entries(): array
    {
        $root = rtrim(url('/'), '/');
        $addresses = [];

        foreach (TypeArchives::shown() as $type => $path) {
            if (TitlesAndListing::hidesArchive($type) || ! $this->items->any($type)) {
                continue;
            }

            $addresses[] = new SitemapAddress($root.PublicRequestPath::encode(Addresses::toUrl($path)), $this->items->newest($type));
        }

        foreach ($this->languages->shown() as $tag) {
            $stored = $this->languages->stored($tag);

            if ($stored === null) {
                continue;
            }

            $prefix = $this->languages->prefix($tag);
            $home = ListingTypes::onHome();
            $newest = $this->items->newestIn(array_map(fn (ContentType $type): string => $type->key, app(ContentTypeRegistry::class)->all()), $stored);

            if ($newest !== null) {
                $addresses[] = new SitemapAddress($root.'/'.PublicRequestPath::encode($prefix).'/', $home === [] ? $newest : ($this->items->newestIn($home, $stored) ?? $newest));
            }

            foreach (TypeArchives::shown() as $type => $path) {
                if (TitlesAndListing::hidesArchive($type) || ! $this->items->any($type, $stored)) {
                    continue;
                }

                $addresses[] = new SitemapAddress($root.PublicRequestPath::encode(Addresses::toUrl($prefix.'/'.$path)), $this->items->newestIn([$type], $stored));
            }
        }

        return $addresses;
    }
}
