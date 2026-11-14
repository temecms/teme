<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * One panel on the admin's Home screen, declared once. Core's panels and, in
 * phase 5, plugins' are the same kind of entry (I-200).
 */
final readonly class DashboardPanel
{
    /**
     * @param  class-string<DashboardPanelQuery>  $query  the one bounded query that fills it
     * @param  list<string>  $actions  ContentType::ACTIONS keys the viewer must hold on a type for its items to be asked for; never ability names
     * @param  string|null  $seeAllTab  the main type's list tab See all opens, or none
     * @param  array<string, string>  $seeAllQuery  list filters See all adds to the tab, such as review=pending
     * @param  bool  $seeAllMine  whether See all narrows the list to the viewer's own items
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $order,
        public string $query,
        public array $actions = [],
        public ?string $seeAllTab = null,
        public bool $seeAllMine = false,
        public string $owner = 'core',
        public array $seeAllQuery = [],
    ) {}
}
