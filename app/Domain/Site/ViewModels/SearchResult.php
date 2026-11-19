<?php

/**
 * One search result: the item, as every listing shows it, and its snippet,
 * safe HTML with the matches marked (App\Domain\Search\Snippet).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One search result: the item, as every listing shows it, and its snippet,
 * safe HTML with the matches marked (App\Domain\Search\Snippet).
 */
final readonly class SearchResult
{
    /**
     * @param  ListingItem  $item  the item, as every listing shows it
     * @param  string  $snippetHtml  its snippet, safe HTML with the matches marked
     */
    public function __construct(
        public ListingItem $item,
        public string $snippetHtml,
    ) {}
}
