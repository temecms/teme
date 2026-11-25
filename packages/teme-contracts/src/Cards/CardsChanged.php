<?php

/**
 * Tells Teme that what a plugin's cards draw changed, such as an ad it
 * keeps, so pages that drew its cards are purged after the Operation
 * commits.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * Tells Teme that what a plugin's cards draw changed, so the pages that
 * drew them are purged after the Operation commits. Offered to a plugin
 * granted pages.insert. Call it only inside an Operation's perform().
 *
 * @since 1.5.0
 */
interface CardsChanged
{
    /**
     * Notes that this plugin's cards changed, for the page cache to purge after commit.
     *
     * @since 1.5.0
     */
    public function notify(): void;
}
