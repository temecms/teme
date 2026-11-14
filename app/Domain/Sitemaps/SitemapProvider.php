<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps;

/**
 * A source of child sitemaps (I-306): core's site address, items, terms
 * and authors, and later a plugin's news or video sitemap or a feed's. It
 * names its children, finds where each page after a child's first starts,
 * and draws a page. Registered under TAG from its part's own provider, in
 * register(); the index lists its children after those registered before.
 */
interface SitemapProvider
{
    public const string TAG = 'teme.sitemaps.providers';

    /**
     * Its children's names, in the order the index lists them: lower-case
     * letters and digits joined by hyphens, never another provider's; none
     * while it has nothing to list.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * The first key of each page of this child after its first, in the
     * child's order: lower-case letters and digits, at most 26 of them.
     * None when the child has one page.
     *
     * @return list<string>
     */
    public function starts(string $name): array;

    /**
     * One page of this child: its entries from the key $from in the
     * child's order, the first page when null, at most ListedItems::PAGE of
     * them, and none when none from there may be listed.
     */
    public function page(string $name, ?string $from): SitemapPage;

    /**
     * The page-cache tags of the lists this child is made from: a change to
     * one of them changes its pages and where they start.
     *
     * @return list<string>
     */
    public function tags(string $name): array;
}
