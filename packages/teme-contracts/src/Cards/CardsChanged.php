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
 * granted pages.insert. Call it inside an Operation's perform(), or from a
 * job: a job's call purges, after its work commits, the pages of the
 * content items it names, or else the whole site at most once a minute.
 *
 * @since 1.5.0
 */
interface CardsChanged
{
    /**
     * Notes that this plugin's cards changed, for the page cache to purge after commit.
     *
     * @param  list<string>|null  $contentIds  the content items whose pages changed, from a job; null for the whole site
     *
     * @since 1.5.0
     */
    public function notify(?array $contentIds = null): void;
}
