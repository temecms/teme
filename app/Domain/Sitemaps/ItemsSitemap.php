<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\Queries\SitemapImages;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;

/**
 * A child for each public content type with addresses and a live item
 * (I-296): its listed items in ID order, each at its address with when it
 * last changed and its images. A type its hide box hides has none, as its
 * pages say noindex.
 */
final readonly class ItemsSitemap implements SitemapProvider
{
    public const string PREFIX = 'items-';

    public function __construct(
        private ContentTypeRegistry $types,
        private ListedItems $items,
        private SitemapImages $images,
    ) {}

    public function names(): array
    {
        $addresses = AddressSettings::current();
        $names = [];

        foreach ($this->types->all() as $type) {
            if ($type->public && $addresses->hasPattern($type->key) && ! TitlesAndListing::hidesType($type->key) && $this->items->any($type->key)) {
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
        $root = rtrim(url('/'), '/');

        return new SitemapPage(array_map(fn (array $row): SitemapAddress => new SitemapAddress(
            $root.PublicRequestPath::encode(Addresses::toUrl($row['path'], $type)),
            CarbonImmutable::parse($row['changed_at']),
            $images[$row['id']] ?? [],
        ), $rows));
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
