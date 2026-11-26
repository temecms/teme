<?php

/**
 * `<x-teme::items type="docs" :limit="20" />`: a type's published items,
 * listed from any template without a region, each as a listing item (title,
 * address, excerpt, image, authors and fields). The page records the list
 * as it does any listing, so adding or taking off an item sets it aside.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\PageLanguage;
use App\Domain\Site\ListingViews;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::items type="docs" :limit="20" />`: a public type's published
 * items in the page's language, newest first or in menu order, at most 100.
 * With `partial="partials.doc-list"` the theme's own partial draws them, given
 * `$items`; without it a plain list of links is drawn. Nothing for a type
 * that is unknown or not public.
 */
final class Items extends Component
{
    /** The most items one list holds. */
    public const int MOST = 100;

    /** @var list<ListingItem> */
    public array $items = [];

    /**
     * @param  string  $type  the type's key
     * @param  int  $limit  how many items, from 1 to 100
     * @param  string  $order  `newest` (the default) or `menu` for the order a hierarchical type's items are arranged in
     * @param  string|null  $partial  the theme's own view to draw them with, such as `partials.doc-list`
     */
    public function __construct(
        ListingViews $listings,
        ContentTypeRegistry $types,
        PageLanguage $language,
        public string $type,
        public int $limit = 10,
        public string $order = 'newest',
        public ?string $partial = null,
    ) {
        if (! $types->has($type) || ! $types->get($type)->public) {
            return;
        }

        app(RenderRecord::class)->tag(PageTags::listOf($type));

        $query = ListingViews::inLanguage(ListingViews::archive([$type]), $language->stored());
        $query = $order === 'menu'
            ? $query->orderBy('contents.position')->orderBy('contents.id')
            : $query->orderByDesc('contents.published_at')->orderByDesc('contents.id');
        $ids = $query->limit(max(1, min(self::MOST, $limit)))->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();

        $this->items = $listings->items($ids);
    }

    public function render(): View
    {
        return view('teme::components.items');
    }
}
