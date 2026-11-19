<?php

/**
 * Adds markup to public pages at declared places: the head, the start and end
 * of the body, between the items of a list, before or after a theme's region,
 * or fixed to an edge of the screen.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * Adds markup to public pages at declared places: the head, the start and
 * end of the body, between the items of a list, before or after a theme's
 * region, or fixed to an edge of the screen. Asked only for a plugin
 * granted pages.insert, and only on public web pages; core cleans and draws
 * what it gives.
 *
 * @since 1.0.0
 */
interface PageInsertionContributor
{
    /**
     * Its insertions for this page; none where it adds nothing.
     *
     * @return list<PageInsertion>
     *
     * @since 1.0.0
     */
    public function pageInsertions(PageFacts $page): array;
}
