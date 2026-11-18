<?php

/**
 * What a public page is, so a contributor can decide what to give: its kind,
 * its path, its language, and for an article or a term's archive what it is
 * about.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * What a public page is, so a contributor can decide what to give: its
 * kind, its path, its language, and for an article or a term's archive what
 * it is about. Never anything about the visitor: the page is cached whole
 * for everyone.
 *
 * @since 1.0.0
 */
final readonly class PageFacts
{
    /**
     * @param  'article'|'home'|'latest'|'archive'|'term'|'author'|'date'|'search'  $kind
     * @param  string  $path  the page's path, relative, starting with / and with no query
     * @param  string|null  $type  the content type of an article or a type's archive
     * @param  string|null  $contentId  an article's ID
     * @param  array<string, list<string>>  $termIdsByTaxonomy  an article's terms, or a term archive's own term, by taxonomy key
     * @param  list<string>  $authorIds  an article's authors, or an author archive's own
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $kind,
        public string $path,
        public string $language,
        public ?string $type,
        public ?string $contentId,
        public array $termIdsByTaxonomy,
        public array $authorIds,
        public int $pageNumber,
    ) {}
}
