<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

use Carbon\CarbonImmutable;

/**
 * One address a sitemap lists: absolute, made at render from the site's
 * address; when its page last changed, if that is known; and the absolute
 * addresses of its images.
 */
final readonly class SitemapAddress
{
    /**
     * @param  list<string>  $images
     */
    public function __construct(
        public string $loc,
        public ?CarbonImmutable $lastmod = null,
        public array $images = [],
    ) {}
}
