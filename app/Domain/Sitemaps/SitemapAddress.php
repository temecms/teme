<?php

/**
 * One address a sitemap lists: absolute, made at render from the site's
 * address; when its page last changed, if that is known; the absolute
 * addresses of its images; and its language versions, `x-default` among
 * them, when it has others (I-3360).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use Carbon\CarbonImmutable;

final readonly class SitemapAddress
{
    /**
     * @param  list<string>  $images
     * @param  list<array{hreflang: string, href: string}>  $alternates
     */
    public function __construct(
        public string $loc,
        public ?CarbonImmutable $lastmod = null,
        public array $images = [],
        public array $alternates = [],
    ) {}
}
