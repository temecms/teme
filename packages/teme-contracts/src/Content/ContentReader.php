<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use InvalidArgumentException;

/**
 * Reads the site's content for a plugin granted content.read: published
 * items for anyone, and a draft for a person who may edit it.
 *
 * @since 0.9.0
 */
interface ContentReader
{
    /**
     * Published, public items, newest first, one page at a time: what a
     * visitor can open. Each address is made when the page is read.
     *
     * @throws InvalidArgumentException for a cursor this reader didn't give, or a value outside ItemQuery's bounds
     *
     * @since 0.9.0
     */
    public function published(ItemQuery $query): ItemPage;

    /**
     * The item's working revision, as the editor holds it, for a person who
     * may edit it.
     *
     * @throws ReadRefused when the person isn't the one this work acts for, or may not edit it
     *
     * @since 0.9.0
     */
    public function draft(string $itemId, string $actingPersonId): ItemSnapshot;
}
