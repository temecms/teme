<?php

/**
 * One panel a plugin adds to Home, as its manifest declares it: its key, the
 * plugin's area it reads, its words, its width and the blocks of one page
 * that fill it (docs/guides/developers/plugins/home-panels.md).
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Dashboard\DashboardPanel;

final readonly class HomePanelDeclaration
{
    /**
     * @param  list<string>  $blocks  the ids of blocks on the page, in the order the panel shows them
     */
    public function __construct(
        public string $key,
        public AreaPlugin $plugin,
        public string $title,
        public ?string $size,
        public int $order,
        public string $page,
        public array $blocks,
    ) {}

    /**
     * The registry entry for this panel: owned by its plugin, kind plugin, and
     * with no query, because its blocks are read by the Home screen itself.
     *
     * @since 1.8.0
     */
    public function panel(): DashboardPanel
    {
        return new DashboardPanel(
            key: $this->key,
            label: $this->title,
            order: $this->order,
            query: null,
            owner: $this->plugin->name,
            size: $this->size,
            kind: 'plugin',
        );
    }
}
