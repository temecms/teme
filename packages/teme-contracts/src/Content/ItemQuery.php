<?php

/**
 * Which published items ContentReader::published() lists, and from where.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;

/**
 * Which published items ContentReader::published() lists, and from where.
 *
 * @since 1.0.0
 */
final readonly class ItemQuery
{
    /**
     * @param  list<string>  $types  content-type keys, at most 10; empty for every public type
     * @param  string|null  $language  a site language's BCP 47 tag; null for every language
     * @param  array<string, list<string>>  $terms  term slugs by taxonomy, at most 10 in all; an item matches any term of each taxonomy
     * @param  DateTimeImmutable|null  $publishedSince  only items published at or after this moment
     * @param  string|null  $after  the cursor ItemPage gave; null for the first page
     * @param  int  $limit  1 to 200
     *
     * @since 1.0.0
     */
    public function __construct(
        public array $types = [],
        public ?string $language = null,
        public array $terms = [],
        public ?DateTimeImmutable $publishedSince = null,
        public ?string $after = null,
        public int $limit = 100,
    ) {}
}
