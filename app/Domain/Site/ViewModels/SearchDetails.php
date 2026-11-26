<?php

/**
 * What only a search's listing has, as `$page->search`: the query as it was
 * searched (trimmed and cut), each result with its snippet, in the listing's
 * order, how many there are (capped, and whether it was), and whether the
 * query was blank, when nothing is searched.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * What only a search's listing has, as `$page->search`: the query as it was
 * searched (trimmed and cut), each result with its snippet, in the listing's
 * order, how many there are (capped, and whether it was), and whether the
 * query was blank, when nothing is searched.
 */
final readonly class SearchDetails
{
    /**
     * @param  string  $query  the query as it was searched, trimmed and cut
     * @param  list<SearchResult>  $results  each result with its snippet, in the listing's order
     * @param  int  $total  how many results there are, capped
     * @param  bool  $totalCapped  whether the total was capped
     * @param  bool  $empty  whether the query was blank, so nothing was searched
     * @param  string|null  $section  the content type key the search is filtered to; null for every type
     * @param  list<SearchSection>  $sections  each type with matches, in the registry's order, with its count across all pages; none for a blank query
     * @param  string|null  $allUrl  the address of this search with no section chosen, query and language kept; null for a blank query
     * @param  int  $allTotal  how many results there are in every section together, capped; 0 for a blank query
     */
    public function __construct(
        public string $query,
        public array $results,
        public int $total,
        public bool $totalCapped,
        public bool $empty,
        public ?string $section = null,
        public array $sections = [],
        public ?string $allUrl = null,
        public int $allTotal = 0,
    ) {}
}
