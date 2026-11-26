<?php

/**
 * A page of hits, and how many there are in all.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * A page of hits, and how many there are in all.
 *
 * @since 0.1.0
 */
final readonly class SearchHits
{
    /**
     * @param  list<SearchHit>  $hits
     * @param  int  $total  capped: counting every match of a common word is wasted work
     * @param  bool  $totalCapped  whether there are more than $total
     * @param  array<string, int>  $counts  matches of each content type across every type searched, by type key, only where the request asked for them with `counts` and the engine counts them; empty otherwise (since next)
     *
     * @since 0.1.0
     */
    public function __construct(
        public array $hits,
        public int $total,
        public bool $totalCapped,
        public array $counts = [],
    ) {}
}
