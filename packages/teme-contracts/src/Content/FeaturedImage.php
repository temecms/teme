<?php

/**
 * A published item's featured image for a card: its public address and the
 * sizes that exist, never a draft or a private item's.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * A published item's featured image for a card: its public address and the
 * sizes that exist, never a draft or a private item's. Addresses are made
 * when read from the current site address.
 *
 * @since next
 */
final readonly class FeaturedImage
{
    /**
     * @param  string  $id  the media item's ID
     * @param  string  $address  the main public address (the widest size), absolute
     * @param  list<ImageSize>  $sizes  every size that exists, narrowest first
     * @param  string|null  $alt  the alt text when set; null when empty
     *
     * @since next
     */
    public function __construct(
        public string $id,
        public string $address,
        public array $sizes,
        public ?string $alt = null,
    ) {}
}
