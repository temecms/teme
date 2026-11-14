<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Models\Content;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Posts related to an article, each with its reason (I-258): through its
 * rarest shared term first, the one fewest items of its type carry, then the
 * next, until there are enough. Nothing shared means none: it never falls
 * back to the latest posts, which aren't related (study Q §4). One query for
 * all the terms, each term's items read from its index.
 */
final readonly class RelatedPosts
{
    /** The newest items taken from each term. */
    private const int PER_TERM = 10;

    public function __construct(
        private ListingViews $listings,
        private TaxonomyRegistry $taxonomies,
    ) {}

    /**
     * Up to $limit related items of the article's type, each with why:
     * "Also in News" for a hierarchical taxonomy's term, "Also tagged
     * Herons" for a flat one's.
     *
     * @return list<array{item: ListingItem, reason: string}>
     */
    public function for(Content $post, int $limit = 3): array
    {
        $type = (string) $post->type;

        // Rarest first, counted for the article's own type.
        $terms = DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->leftJoin('term_counts', fn ($join) => $join->on('term_counts.term_id', '=', 'terms.id')->where('term_counts.type', $type))
            ->where('content_terms.content_id', $post->id)
            ->where('content_terms.scope', 'published')
            ->orderByRaw('coalesce(term_counts.published, 0)')
            ->orderBy('terms.id')
            ->get(['terms.id', 'terms.name', 'terms.taxonomy'])
            ->keyBy('id');

        if ($terms->isEmpty()) {
            return [];
        }

        // The newest items filed under each of its terms are what this list
        // is made of, and each reason names a term (I-263).
        foreach ($terms as $term) {
            app(RenderRecord::class)->tag(PageTags::filedUnder((string) $term->taxonomy, (string) $term->id), PageTags::term((string) $term->id));
        }

        // Each term's newest items, all in one query however many terms the
        // article has (1.6h): the same PER_TERM a term, in the order above,
        // so the items and their reasons are those a query per term found.
        // In the article's own language only (I-3360).
        $newest = ListingViews::inLanguage(ListingViews::archive([$type]), $post->language === null ? null : (string) $post->language)
            ->join('content_terms', 'content_terms.content_id', '=', 'contents.id')
            ->where('content_terms.scope', 'published')
            ->whereColumn('content_terms.term_id', 'wanted.term_id')
            ->where('contents.id', '<>', $post->id)
            // Only public items go beyond the site's own pages (I-1700).
            ->where('contents.visibility', 'public')
            ->orderByDesc('contents.published_at')->orderByDesc('contents.id')
            ->limit(self::PER_TERM)
            ->select(['contents.id', 'contents.published_at']);

        // PDO can't bind an array: the IDs go in as one array literal, as bpchar
        // so content_terms' index answers each term.
        $rows = DB::query()
            ->fromRaw('unnest(?::bpchar[]) WITH ORDINALITY AS wanted(term_id, position)', ['{'.$terms->keys()->implode(',').'}'])
            ->joinLateral($newest, 'newest')
            ->orderBy('wanted.position')->orderByDesc('newest.published_at')->orderByDesc('newest.id')
            ->get(['wanted.term_id', 'newest.id']);

        $reasons = [];

        foreach ($rows as $row) {
            if (count($reasons) >= $limit) {
                break;
            }

            $term = $terms[(string) $row->term_id];
            $reasons[(string) $row->id] ??= $this->reason((string) $term->taxonomy, (string) $term->name);
        }

        $related = [];

        foreach ($this->listings->itemsById(array_map('strval', array_keys($reasons))) as $id => $item) {
            $related[] = ['item' => $item, 'reason' => $reasons[$id]];
        }

        return $related;
    }

    /**
     * Why an item is related, from its taxonomy's shape, never its key.
     */
    private function reason(string $taxonomy, string $name): string
    {
        $hierarchical = $this->taxonomies->has($taxonomy) && $this->taxonomies->get($taxonomy)->hierarchical;

        return $hierarchical ? __('Also in :name', ['name' => $name]) : __('Also tagged :name', ['name' => $name]);
    }
}
