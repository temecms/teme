<?php

/**
 * One panel on the admin's Home screen, declared once. Core's panels and, in
 * phase 5, plugins' are the same kind of entry (I-200).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Dashboard;

final readonly class DashboardPanel
{
    /**
     * The widths a panel may declare or a person may choose.
     *
     * @since 0.8.0-alpha.1
     */
    public const array SIZES = ['small', 'medium', 'large', 'full'];

    /**
     * The kinds of body a panel may draw.
     *
     * @since 0.8.0-alpha.1
     */
    public const array KINDS = ['rows', 'quick-draft', 'site-status'];

    /**
     * @param  class-string<DashboardPanelQuery>|null  $query  the one bounded query that fills a rows panel; none for quick draft or site status
     * @param  list<string>  $actions  ContentType::ACTIONS keys the viewer must hold on a type for its items to be asked for; never ability names
     * @param  string|null  $seeAllTab  the main type's list tab See all opens, or none
     * @param  array<string, string>  $seeAllQuery  list filters See all adds to the tab, such as review=pending
     * @param  bool  $seeAllMine  whether See all narrows the list to the viewer's own items
     * @param  string|null  $size  how wide it is drawn until the person changes it; one of self::SIZES, or null so the registry picks Medium for core and Small for a plugin (Settled 2)
     * @param  string  $kind  what it draws: rows from its query, the quick draft form, or the site status summary; one of self::KINDS
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $order,
        public ?string $query,
        public array $actions = [],
        public ?string $seeAllTab = null,
        public bool $seeAllMine = false,
        public string $owner = 'core',
        public array $seeAllQuery = [],
        public ?string $size = null,
        public string $kind = 'rows',
    ) {}
}
