<?php

/**
 * The posts a featured posts card lists, read when the page is drawn: the
 * chosen ones in their order, or the newest of a type in the page's
 * language, optionally filed under one term. The page records what it shows
 * by the page cache's own tags, so the purges that exist already redraw it
 * (I-263).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\Types\FeaturedPostsCard;
use App\Domain\Languages\PageLanguage;
use App\Domain\Site\ListingViews;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class FeaturedPosts
{
    public function __construct(
        private ListingViews $views,
        private RenderRecord $record,
        private ContentTypeRegistry $types,
        private PageLanguage $language,
    ) {}

    /**
     * The list a card with these attributes shows: published, live items
     * that aren't private, as ListingItem, at most twelve; [] for a type
     * that isn't public or a term that no longer exists.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<ListingItem>
     */
    public function items(array $attrs): array
    {
        if (($attrs['source'] ?? 'latest') === 'chosen') {
            $ids = array_slice(array_values(array_filter((array) ($attrs['items'] ?? []), 'is_string')), 0, FeaturedPostsCard::MOST);

            if ($ids === []) {
                return [];
            }

            // Each chosen item is recorded, shown or not, so a change to any of them purges the page.
            $this->record->tag(...array_map(PageTags::content(...), $ids));

            $public = array_values(array_map(
                fn (ContentType $type): string => $type->key,
                array_filter($this->types->all(), fn (ContentType $type): bool => $type->public),
            ));
            $live = ListingViews::archive($public)->whereIn('contents.id', $ids)->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();

            // Only published, live, public items, in the order they were chosen.
            return $this->views->items(array_values(array_filter($ids, fn (string $id): bool => in_array($id, $live, true))));
        }

        $type = (string) ($attrs['type'] ?? 'post');

        if (! $this->types->has($type) || ! $this->types->get($type)->public) {
            return [];
        }

        $this->record->tag(PageTags::listOf($type));
        $query = ListingViews::inLanguage(ListingViews::archive([$type]), $this->language->stored());
        $termId = $attrs['termId'] ?? null;

        if (is_string($termId) && $termId !== '') {
            $taxonomy = DB::table('terms')->where('id', $termId)->value('taxonomy');

            if (! is_string($taxonomy)) {
                $this->record->tag(PageTags::term($termId));

                return [];
            }

            $this->record->tag(PageTags::filedUnder($taxonomy, $termId), PageTags::term($termId));
            $query->whereExists(fn (Builder $filed) => $filed->selectRaw('1')
                ->from('content_terms')
                ->whereColumn('content_terms.content_id', 'contents.id')
                ->where('content_terms.scope', 'published')
                ->where('content_terms.term_id', $termId));
        }

        $count = max(1, min(FeaturedPostsCard::MOST, (int) ($attrs['count'] ?? 5)));
        $ids = $query->orderByDesc('contents.published_at')->orderByDesc('contents.id')->limit($count)
            ->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();

        return $this->views->items($ids);
    }
}
