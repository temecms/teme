<?php

/**
 * A few matching items for a query typed so far, for a theme's search
 * overlay: the first of the public search's own results, so what a reader is
 * shown here is what the search page would show, in the language asked.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ListingViews;
use Teme\Contracts\Search\SearchHit;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;

final readonly class Suggestions
{
    /** The most suggestions one answer holds. */
    public const int MOST = 8;

    /** The fewest characters a query needs before anything is suggested. */
    public const int LEAST_CHARACTERS = 2;

    /** The longest query read, in characters, as the search page reads it. */
    private const int QUERY_LENGTH = 200;

    public function __construct(
        private SiteSearch $search,
        private ListingViews $listings,
        private SiteLanguages $languages,
    ) {}

    /**
     * Up to MOST visible items matching the query in this language, best
     * first: each with its published title, its address and its type key.
     * Nothing for a query shorter than LEAST_CHARACTERS. A tag that isn't
     * one of the site's shown languages is read as the default's.
     *
     * @return list<array{title: string, url: string, type: string}>
     */
    public function for(string $query, ?string $tag): array
    {
        $query = mb_substr(trim($query), 0, self::QUERY_LENGTH);

        if (mb_strlen($query) < self::LEAST_CHARACTERS) {
            return [];
        }

        $stored = $tag !== null && $this->languages->has($tag) && $tag !== $this->languages->defaultTag() && $this->languages->isShown($tag) ? $tag : null;
        $hits = $this->search->search(new SearchRequest($query, 1, self::MOST, null, $stored));
        $items = $this->listings->itemsById(array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits));
        $suggestions = [];

        foreach ($hits->hits as $hit) {
            $item = $items[$hit->contentId] ?? null;

            if ($item !== null && count($suggestions) < self::MOST) {
                $suggestions[] = ['title' => $item->title, 'url' => $item->url, 'type' => $item->type];
            }
        }

        return $suggestions;
    }
}
