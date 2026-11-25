<?php

/**
 * A plugin card whose `choice` fields list options the plugin gives while
 * an editor fills the card in, such as the ads it keeps.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * A plugin card whose `choice` fields list options the plugin gives while
 * an editor fills the card in. Asked in the admin, within a time budget;
 * one that throws or runs over gives no options.
 *
 * @since next
 */
interface OffersCardChoices
{
    /**
     * The options for one choice field, at most 50, best first.
     *
     * @param  string  $field  the field's key
     * @param  CardRegion  $region  the region the card is in, with its size when the theme gives one
     * @param  string  $search  what the editor typed, '' for none
     *
     * @return list<CardChoice>
     *
     * @since next
     */
    public function choices(string $field, CardRegion $region, string $search = ''): array;
}
