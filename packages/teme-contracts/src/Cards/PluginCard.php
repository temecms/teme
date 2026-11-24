<?php

/**
 * One card a plugin offers: it draws the card's markup on a public page
 * from the values an editor gave its fields.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * One card a plugin offers. Core draws its fields in the editor from the
 * manifest, keeps its values in the region's document, and asks render()
 * only on a public web page, within a time budget. What it returns is
 * cleaned as a page insertion is, and wrapped in an element carrying the
 * card's key, which the theme styles.
 *
 * @since next
 */
interface PluginCard
{
    /**
     * The card's markup, or '' to draw nothing, as for a choice that no
     * longer exists. The values are as stored, so escape them.
     *
     * @since next
     */
    public function render(CardInstance $card): string;
}
