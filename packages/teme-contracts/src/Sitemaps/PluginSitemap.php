<?php

/**
 * One of a plugin's sitemaps: where each of its pages after the first starts,
 * and the entries of one page.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Sitemaps;

/**
 * One of a plugin's sitemaps: where each of its pages after the first
 * starts, and the entries of one page. Each call is answered within 2
 * seconds; read what the plugin already keeps, never call a service.
 *
 * @since 1.1.0
 */
interface PluginSitemap
{
    /** The most entries one page may hold. @since 1.1.0 */
    public const int MOST_ENTRIES = 1_000;

    /** A page's first key: lower-case letters and digits, at most 26. @since 1.1.0 */
    public const string KEY = '/^[0-9a-z]{1,26}$/D';

    /**
     * The first key of each page after the first, in order; none when it
     * has one page.
     *
     * @return list<string>
     *
     * @since 1.1.0
     */
    public function starts(): array;

    /**
     * One page's entries, at most 1,000: the first page when $from is null,
     * else the page that starts at that key.
     *
     * @return list<SitemapEntry>
     *
     * @since 1.1.0
     */
    public function entries(?string $from): array;
}
