<?php

/**
 * One size of a published item's featured image: its name, pixel size and
 * public address built from the current site address.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * One size of a published item's featured image: its name, pixel size and
 * public address built from the current site address.
 *
 * @since next
 */
final readonly class ImageSize
{
    /**
     * @param  string  $name  the size's key, such as w800 or thumb
     * @param  int  $width  its width in pixels
     * @param  int  $height  its height in pixels
     * @param  string  $address  its public address, absolute, made when read
     *
     * @since next
     */
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public string $address,
    ) {}
}
