<?php

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * What a contributor learns about the article so it can decide which
 * insertions to give: identity, type, terms, language, authors, size, and
 * its path.
 * Core skips contributors entirely when the item has opted out.
 *
 * @since 0.9.0
 */
final readonly class ArticleFacts
{
    /**
     * @param  array<string, list<string>>  $termIdsByTaxonomy  term IDs by taxonomy key
     * @param  list<string>  $authorIds
     * @param  string  $path  the article's current path, relative, starting with /; '' where none is known
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $contentId,
        public string $type,
        public array $termIdsByTaxonomy,
        public string $language,
        public array $authorIds,
        public int $words,
        public int $paragraphs,
        public string $path = '',
    ) {}
}
