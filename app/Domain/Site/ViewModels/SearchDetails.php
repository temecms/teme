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
     */
    public function __construct(
        public string $query,
        public array $results,
        public int $total,
        public bool $totalCapped,
        public bool $empty,
    ) {}
}
