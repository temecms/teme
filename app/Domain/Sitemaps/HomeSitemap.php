<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Cache\PageTags;
use App\Domain\Sitemaps\Queries\ListedItems;

/**
 * The site's address as a child of its own: dated by the newest live item of
 * the types Reading ticks while it shows the latest posts; while it shows a
 * page, dated by that page, and only while the page is live and its own box
 * and canonical let it be listed, its type's hide box aside (I-854). A front
 * page is never listed at its own address, which answers 301.
 */
final readonly class HomeSitemap implements SitemapProvider
{
    public const string NAME = 'home';

    public function __construct(private ListedItems $items) {}

    public function names(): array
    {
        return $this->entry() === null ? [] : [self::NAME];
    }

    public function starts(string $name): array
    {
        return [];
    }

    public function page(string $name, ?string $from): SitemapPage
    {
        $entry = $this->entry();

        return new SitemapPage($entry === null ? [] : [$entry]);
    }

    public function tags(string $name): array
    {
        $front = self::frontPage();

        return match (true) {
            $front !== null => [PageTags::content($front)],
            default => array_map(PageTags::listOf(...), ListingTypes::onHome()),
        };
    }

    private function entry(): ?SitemapAddress
    {
        $root = rtrim(url('/'), '/').'/';
        $front = self::frontPage();

        if ($front !== null) {
            $changed = $this->items->listed($front);

            return $changed === null ? null : new SitemapAddress($root, $changed);
        }

        $types = ListingTypes::onHome();

        return new SitemapAddress($root, $types === [] ? null : $this->items->newest(...$types));
    }

    /**
     * The page the site's address shows, or null while it shows the latest posts.
     */
    private static function frontPage(): ?string
    {
        $settings = AddressSettings::current();

        return $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId !== '' ? $settings->frontPageId : null;
    }
}
