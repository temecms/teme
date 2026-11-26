<?php

/**
 * The search page as its template sees it: 1.6c's ListingView, the class
 * every archive receives, with a search subject, the results' items in hit
 * order, pages at `/page/N/?s=…` (after the page language's prefix), and the search's own details beside them.
 * A blank query searches nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\Pagination;
use App\Domain\Site\ViewModels\SearchDetails;
use App\Domain\Site\ViewModels\SearchResult;
use App\Domain\Site\ViewModels\SearchSection;
use App\Domain\Site\ViewModels\Subject;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Search\SearchHit;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;

final readonly class SearchViews
{
    /** The longest query searched and shown, in characters (as PostgresSiteSearch reads it). */
    private const int QUERY_LENGTH = 200;

    public function __construct(
        private SiteSearch $search,
        private ListingViews $listings,
    ) {}

    /**
     * One page of a search for this query, among one language's items (the
     * default's when null); a page past the last has no items. A section
     * that isn't a searchable type is ignored, as no section.
     */
    public function for(string $query, int $page, ?string $language = null, ?string $section = null): ListingView
    {
        $query = mb_substr(trim($query), 0, self::QUERY_LENGTH);

        if ($query === '') {
            return new ListingView(new Subject('search', 'Search'), [], new Pagination(1, 1, null, null), new SearchDetails('', [], 0, false, true), total: 0);
        }

        $perPage = $this->listings->perPage();
        $types = app(ContentTypeRegistry::class);
        $section = $section !== null && $types->has($section) && $types->get($section)->searchable ? $section : null;
        $hits = $this->search->search(new SearchRequest($query, $page, $perPage, $section === null ? null : [$section], $language, true));
        $items = $this->listings->itemsById(array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits));
        $results = [];

        // Paired as RelatedPosts pairs each post with its reason, in hit order.
        foreach ($hits->hits as $hit) {
            if (isset($items[$hit->contentId])) {
                $results[] = new SearchResult($items[$hit->contentId], $hit->snippetHtml);
            }
        }

        $base = '?s='.rawurlencode($query);
        $suffix = $base.($section === null ? '' : '&section='.rawurlencode($section));
        $home = '/'.ListingViews::prefixed($language, '').($language === null ? '' : '/');
        $pages = max(1, (int) ceil($hits->total / $perPage));

        return new ListingView(
            new Subject('search', 'Search results for “'.$query.'”'),
            array_map(fn (SearchResult $result) => $result->item, $results),
            new Pagination(
                $page,
                $pages,
                $page > 1 ? Pagination::urlFor($home, $page - 1).$suffix : null,
                $page < $pages ? Pagination::urlFor($home, $page + 1).$suffix : null,
                $home,
                $suffix,
            ),
            new SearchDetails(
                $query,
                $results,
                $hits->total,
                $hits->totalCapped,
                false,
                $section,
                $this->sections($hits->counts, $section, $home, $base),
                $home.$base,
                array_sum($hits->counts),
            ),
            total: $hits->totalCapped ? null : $hits->total,
        );
    }

    /**
     * The types that matched, in the registry's order, each with its count
     * and the address filtering the search to it, its page 1.
     *
     * @param  array<string, int>  $counts  matches by type key, from the search
     *
     * @return list<SearchSection>
     */
    private function sections(array $counts, ?string $section, string $home, string $base): array
    {
        $registry = app(ContentTypeRegistry::class);
        $sections = [];

        foreach ($registry->all() as $type) {
            if (($counts[$type->key] ?? 0) > 0) {
                $sections[] = new SearchSection($type->key, $type->plural, $counts[$type->key], $home.$base.'&section='.rawurlencode($type->key), $type->key === $section);
            }
        }

        return $sections;
    }
}
