<?php

/**
 * What a contributor learns about the article so it can decide which
 * insertions to give: identity, type, terms, language, authors, size, path,
 * title, social fields and excerpt.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * What a contributor learns about the article so it can decide which
 * insertions to give: identity, type, terms, language, authors, size, path,
 * title, social fields and excerpt.
 * Core skips contributors entirely when the item has opted out.
 *
 * @since 1.0.0
 */
final readonly class ArticleFacts
{
    /**
     * @param  array<string, list<string>>  $termIdsByTaxonomy  term IDs by taxonomy key
     * @param  list<string>  $authorIds
     * @param  string  $path  the article's current path, relative, starting with /; '' where none is known
     * @param  string  $title  the article's title as published
     * @param  string|null  $socialTitle  the social title when set; null when empty (core falls back for sharing)
     * @param  string|null  $socialDescription  the social description when set; null when empty
     * @param  string|null  $excerpt  the excerpt when set; null when empty
     *
     * @since 1.0.0
     * @since next Takes $title, $socialTitle, $socialDescription and $excerpt.
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
        public string $title = '',
        public ?string $socialTitle = null,
        public ?string $socialDescription = null,
        public ?string $excerpt = null,
    ) {}
}
