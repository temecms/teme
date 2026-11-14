<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

/**
 * One page of a child sitemap: its addresses in the child's order, and the
 * page-cache tags of what they show beyond the child's own lists.
 */
final readonly class SitemapPage
{
    /**
     * @param  list<SitemapAddress>  $addresses
     * @param  list<string>  $tags
     */
    public function __construct(
        public array $addresses,
        public array $tags = [],
    ) {}
}
