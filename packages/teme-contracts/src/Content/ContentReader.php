<?php

/**
 * Reads the site's content for a plugin granted content.read: published items
 * for anyone, a published public item's card, and drafts and the queue of
 * unpublished items for a person who may edit them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use InvalidArgumentException;

/**
 * Reads the site's content for a plugin granted content.read: published
 * items for anyone, a published public item's card, and drafts and the
 * queue of unpublished items for a person who may edit them.
 *
 * @since 1.0.0
 */
interface ContentReader
{
    /**
     * Published, public items, newest first, one page at a time: what a
     * visitor can open. Each address is made when the page is read.
     *
     * @throws InvalidArgumentException for a cursor this reader didn't give, or a value outside ItemQuery's bounds
     *
     * @since 1.0.0
     */
    public function published(ItemQuery $query): ItemPage;

    /**
     * The item's working revision, as the editor holds it, for a person who
     * may edit it.
     *
     * @throws ReadRefused when the person isn't the one this work acts for, or may not edit it
     *
     * @since 1.0.0
     */
    public function draft(string $itemId, string $actingPersonId): ItemSnapshot;

    /**
     * Unpublished items by status, draft, in review or scheduled, that the
     * person may edit, oldest first, one page at a time: the queue as it
     * stands, including what was there before the plugin was enabled.
     *
     * @throws ReadRefused when the person isn't the one this work acts for
     * @throws InvalidArgumentException for a cursor this reader didn't give, an unknown status, or a value outside QueueQuery's bounds
     *
     * @since 1.0.0
     */
    public function queue(QueueQuery $query, string $actingPersonId): QueuePage;

    /**
     * A published, public item's card: its excerpt and featured image
     * (address and the sizes that exist), built from the published revision
     * and the current site address. Never a draft, private or
     * password-protected item, and never needs a person.
     *
     * @throws ReadRefused when the item isn't a published public item
     *
     * @since next
     */
    public function card(string $itemId): ItemCard;
}
