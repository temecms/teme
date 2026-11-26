<?php

/**
 * A driver that gives the address of an image's size from an outside image
 * service, such as one that resizes or converts images when they are asked
 * for, or null to leave the address to core.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Media;

/**
 * A driver that gives the address of an image's size from an outside image
 * service, or null to leave the address to core, which serves the sizes it
 * made at upload. Asked each time a page or a screen draws the image, so it
 * only reads: it is handed no way to change anything.
 *
 * @since next
 */
interface ImageService
{
    /**
     * The address of this size of this image in this format: an absolute
     * `https://` address on the host the plugin's manifest declares for its
     * image service, or a path on this site starting with a single `/`.
     * Anything else is refused, and core's address is used.
     *
     * @param  string  $mediaId  the media item's ID
     * @param  string  $variant  the size's name, as core made it: `thumb`, `w320` to `w2560`, `social` or `crop-16x9`, `crop-4x3` and `crop-1x1`
     * @param  string  $format  the file format core would serve, as a lower-case extension such as `webp`
     *
     * @since next
     */
    public function imageAddress(string $mediaId, string $variant, string $format): ?string;
}
