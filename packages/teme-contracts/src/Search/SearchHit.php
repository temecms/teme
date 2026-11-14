<?php

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * One item found: its content ID, and a snippet of its text.
 *
 * @since 0.1.0
 */
final readonly class SearchHit
{
    /**
     * @param  string  $snippetHtml  safe HTML: escaped text, with <mark> around the matches
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $contentId,
        public string $snippetHtml,
    ) {}
}
