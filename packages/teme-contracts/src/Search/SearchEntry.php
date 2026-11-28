<?php

/**
 * One published item as a search engine is sent it: its ID, type, language,
 * title and plain text. The engine answers with IDs only, which the site checks.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * A published item as a search engine indexes it.
 *
 * @since 1.8.0
 */
final readonly class SearchEntry
{
    /**
     * @param  string  $contentId  the item's ID
     * @param  string  $type  the item's type
     * @param  string|null  $language  the item's language, or null for the site's
     * @param  string  $title  the item's title
     * @param  string  $text  the item's words, as plain text
     * @param  string|null  $publishedAt  when it was published, as an ISO 8601 string
     *
     * @since 1.8.0
     */
    public function __construct(
        public string $contentId,
        public string $type,
        public ?string $language,
        public string $title,
        public string $text,
        public ?string $publishedAt,
    ) {}
}
