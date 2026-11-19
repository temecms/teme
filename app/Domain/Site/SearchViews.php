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
use App\Domain\Site\ViewModels\Subject;
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
     * default's when null); a page past the last has no items.
     */
    public function for(string $query, int $page, ?string $language = null): ListingView
    {
        $query = mb_substr(trim($query), 0, self::QUERY_LENGTH);

        if ($query === '') {
            return new ListingView(new Subject('search', 'Search'), [], new Pagination(1, 1, null, null), new SearchDetails('', [], 0, false, true));
        }

        $perPage = $this->listings->perPage();
        $hits = $this->search->search(new SearchRequest($query, $page, $perPage, null, $language));
        $items = $this->listings->itemsById(array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits));
        $results = [];

        // Paired as RelatedPosts pairs each post with its reason, in hit order.
        foreach ($hits->hits as $hit) {
            if (isset($items[$hit->contentId])) {
                $results[] = new SearchResult($items[$hit->contentId], $hit->snippetHtml);
            }
        }

        $suffix = '?s='.rawurlencode($query);
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
            new SearchDetails($query, $results, $hits->total, $hits->totalCapped, false),
        );
    }
}
