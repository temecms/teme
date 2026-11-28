<?php

/**
 * The site's public facts, offered to every plugin with no capability: its
 * address, name, language, home page, main feeds and time zone, the same in a
 * request, a queued job and the console.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Site;

/**
 * The site's public facts, offered to every plugin with no capability: its
 * address, name, language, home page, main feeds and time zone, the same
 * in a request, a queued job and the console.
 *
 * @since 1.0.0
 */
interface SiteInfo
{
    /** The site's address, absolute, with no trailing slash. @since 1.0.0 */
    public function address(): string;

    /** The site's name (Settings › General). @since 1.0.0 */
    public function name(): string;

    /** The site's default language as a BCP 47 tag. @since 1.0.0 */
    public function language(): string;

    /** The home page's address, absolute. @since 1.0.0 */
    public function home(): string;

    /**
     * The main feed's address for each site language, absolute, the default language's first.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function feeds(): array;

    /**
     * The site's time zone (Settings › General), an IANA name such as Africa/Lagos.
     *
     * @since next
     */
    public function timeZone(): string;
}
