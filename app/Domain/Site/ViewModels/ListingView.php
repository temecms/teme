<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One page of a listing as its template sees it, as `$page`: what it is
 * about (null for the latest posts at the site's address), its items, and
 * its pages; and, for a search only, what a search has besides, so an
 * archive template drawing a search meets the one shape it always meets.
 */
final readonly class ListingView
{
    /**
     * @param  Subject|null  $subject  what the listing is about; null for the latest posts at the site's address
     * @param  list<ListingItem>  $items  this page's items
     * @param  Pagination  $pagination  the links between the listing's pages
     * @param  SearchDetails|null  $search  what only a search has; null for any other listing
     * @param  list<Crumb>|null  $breadcrumbs  the trail to it, when the active theme draws one
     */
    public function __construct(
        public ?Subject $subject,
        public array $items,
        public Pagination $pagination,
        public ?SearchDetails $search = null,
        public ?array $breadcrumbs = null,
    ) {}
}
