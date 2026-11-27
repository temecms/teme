<?php

/**
 * Where a search engine keeps the published items it answers from: sent each
 * one when it is published, and told to drop one when it is unpublished or
 * removed (docs/guides/developers/plugins/search-engine.md).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * Keeps a search engine's index in step with the site's published items.
 * Core calls it after each change, so it may be behind for a moment.
 *
 * @since next
 */
interface SearchIndex
{
    /**
     * Adds or replaces one published item.
     *
     * @since next
     */
    public function index(SearchEntry $entry): void;

    /**
     * Drops an item that is no longer published.
     *
     * @since next
     */
    public function remove(string $contentId): void;
}
