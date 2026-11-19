<?php

/**
 * A child for each public content type with addresses and a live item
 * (I-296): its listed items in every shown language, in ID order, each at
 * its address with when it last changed, its images, and its other
 * language versions (I-3360). A type its hide box hides has none, as its
 * pages say noindex.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\Queries\SitemapImages;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;

final readonly class ItemsSitemap implements SitemapProvider
{
    public const string PREFIX = 'items-';

    public function __construct(
        private ContentTypeRegistry $types,
        private ListedItems $items,
        private SitemapImages $images,
        private SiteLanguages $languages,
    ) {}

    public function names(): array
    {
        $addresses = AddressSettings::current();
        $names = [];

        foreach ($this->types->all() as $type) {
            if ($type->public && $addresses->hasPattern($type->key) && ! TitlesAndListing::hidesType($type->key) && $this->items->anyShown($type->key)) {
                $names[] = self::PREFIX.$type->key;
            }
        }

        return $names;
    }

    public function starts(string $name): array
    {
        return $this->items->starts(self::type($name));
    }

    public function page(string $name, ?string $from): SitemapPage
    {
        $type = self::type($name);
        $rows = $this->items->page($type, $from);
        $images = $this->images->of(array_column($rows, 'id'));
        $groups = array_values(array_unique(array_filter(array_column($rows, 'group'))));
        $versions = $this->items->versions($groups);
        $root = rtrim(url('/'), '/');

        if ($groups !== []) {
            app(RenderRecord::class)->tag(...array_map(PageTags::translations(...), $groups));
        }

        return new SitemapPage(array_map(fn (array $row): SitemapAddress => new SitemapAddress(
            $root.PublicRequestPath::encode(Addresses::toUrl($row['path'], $type)),
            CarbonImmutable::parse($row['changed_at']),
            $images[$row['id']] ?? [],
            $row['group'] === null ? [] : $this->alternates($versions[$row['group']] ?? [], $root),
        ), $rows));
    }

    /**
     * A group's versions as `hreflang` and address, in the site's language
     * order, `x-default` to the default language's: none for a group of one.
     *
     * @param  list<array{id: string, language: string|null, type: string, path: string}>  $versions
     *
     * @return list<array{hreflang: string, href: string}>
     */
    private function alternates(array $versions, string $root): array
    {
        if (count($versions) < 2) {
            return [];
        }

        $byTag = [];

        foreach ($versions as $version) {
            $byTag[$this->languages->of($version['language'])] = $root.PublicRequestPath::encode(Addresses::toUrl($version['path'], $version['type']));
        }

        $alternates = [];

        foreach ($this->languages->all() as $tag) {
            if (isset($byTag[$tag])) {
                $alternates[] = ['hreflang' => $tag, 'href' => $byTag[$tag]];
            }
        }

        $default = $this->languages->defaultTag();

        if (isset($byTag[$default])) {
            $alternates[] = ['hreflang' => 'x-default', 'href' => $byTag[$default]];
        }

        return $alternates;
    }

    public function tags(string $name): array
    {
        return [PageTags::listOf(self::type($name))];
    }

    private static function type(string $name): string
    {
        return substr($name, strlen(self::PREFIX));
    }
}
