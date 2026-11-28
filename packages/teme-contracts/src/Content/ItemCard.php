<?php

/**
 * A published public item's card for sharing and posting: its excerpt and
 * featured image, never a person and never a draft or private item.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * A published public item's card for sharing and posting: its excerpt and
 * featured image, never a person and never a draft or private item. Built
 * from the published revision and the current site address.
 *
 * @since next
 */
final readonly class ItemCard
{
    /**
     * @param  ItemRef  $item  the published public item
     * @param  string|null  $excerpt  the excerpt someone wrote; null when none
     * @param  FeaturedImage|null  $featuredImage  its featured image; null when none
     *
     * @since next
     */
    public function __construct(
        public ItemRef $item,
        public ?string $excerpt,
        public ?FeaturedImage $featuredImage,
    ) {}
}
