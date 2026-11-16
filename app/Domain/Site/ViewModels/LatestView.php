<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * The latest posts, at the site's address or the posts page's, as
 * `$page`: on page 1 the lead, the newest pinned post or else the newest
 * post, and up to three more pinned posts; then the feed of the rest.
 */
final readonly class LatestView
{
    /**
     * @param  ListingItem|null  $lead  the newest pinned post, or else the newest post; page 1 only
     * @param  list<ListingItem>  $featured  up to three more pinned posts; page 1 only
     * @param  ListingView  $feed  the rest of the posts, as a listing
     * @param  list<Crumb>|null  $breadcrumbs  the trail to it, when the active theme draws one
     */
    public function __construct(
        public ?ListingItem $lead,
        public array $featured,
        public ListingView $feed,
        public ?array $breadcrumbs = null,
    ) {}
}
