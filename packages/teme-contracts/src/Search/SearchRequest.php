<?php

/**
 * What a reader asked: the query as typed, the page wanted, its size, and the
 * language searched.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * What a reader asked: the query as typed, the page wanted, its size, and
 * the language searched.
 *
 * @since 0.1.0
 */
final readonly class SearchRequest
{
    /**
     * @param  list<string>|null  $types  content type keys to search; null means every type the site marks searchable
     * @param  string|null  $language  the language searched, as stored: null for the site's default (since 0.6.0)
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $query,
        public int $page,
        public int $perPage,
        public ?array $types = null,
        public ?string $language = null,
    ) {}
}
