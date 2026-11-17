<?php

declare(strict_types=1);

namespace Teme\Contracts\Sitemaps;

/**
 * A plugin granted sitemaps whose manifest declares sitemaps: core lists
 * each in the site's sitemap index after its own, as `sitemap-{code}-{id}.xml`,
 * and caches its pages as it caches its own.
 *
 * @since 1.1.0
 */
interface HasSitemaps
{
    /**
     * The plugin's sitemaps, by declared sitemap id.
     *
     * @return array<string, PluginSitemap>
     *
     * @since 1.1.0
     */
    public function sitemaps(): array;
}
