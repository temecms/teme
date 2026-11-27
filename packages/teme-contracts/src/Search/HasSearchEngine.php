<?php

/**
 * A plugin granted nothing extra whose manifest fills the search slot: it
 * hands over the search engine that answers site searches in place of core's
 * (docs/guides/developers/plugins/search-engine.md).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Search;

/**
 * A search engine a plugin provides. Its hits are content IDs only; the site
 * re-checks each one's visibility before showing it, so an outside index that
 * holds a draft never shows it.
 *
 * @since next
 */
interface HasSearchEngine
{
    /**
     * The engine that answers the site's searches.
     *
     * @since next
     */
    public function engine(): SiteSearch;
}
