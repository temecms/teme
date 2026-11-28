<?php

/**
 * A published public item's card for a plugin granted content.read: its
 * excerpt and featured image. Its own interface beside ContentReader, so
 * classes that implement ContentReader keep working.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * A published public item's card for a plugin granted content.read, given
 * beside ContentReader to the same plugins.
 *
 * @since next
 */
interface ItemCardReader
{
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
