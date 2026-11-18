<?php

/**
 * Finds published content for a reader's query.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * Finds published content for a reader's query. Core's implementation uses
 * PostgreSQL; a plugin may bind another. It answers with content IDs and
 * snippets, never models, so an engine needs to know nothing of Teme's views.
 *
 * @since 0.1.0
 */
interface SiteSearch
{
    /**
     * The page of hits for the request, most relevant first.
     *
     * @since 0.1.0
     */
    public function search(SearchRequest $request): SearchHits;
}
