<?php

/**
 * A plugin that offers cards editors place in a theme's regions: one card
 * object for each card its manifest declares under contributes.cards.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * A plugin that offers cards editors place in a theme's regions. Teme asks
 * it once as the plugin starts; its keys must be the ids the manifest
 * declares under contributes.cards, no more and no fewer. Asked only of a
 * plugin granted pages.insert.
 *
 * @since next
 */
interface HasCards
{
    /**
     * Its cards, by the id the manifest declares.
     *
     * @return array<string, PluginCard>
     *
     * @since next
     */
    public function cards(): array;
}
