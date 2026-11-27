<?php

/**
 * A plugin granted media.read whose manifest declares an image service:
 * hands over the driver core asks for images' addresses.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Media;

/**
 * A plugin granted media.read whose manifest declares an image service:
 * hands over the driver core asks for images' addresses. One driver is
 * active at a time.
 *
 * @since 1.7.0
 */
interface HasImageService
{
    /**
     * The plugin's image service driver.
     *
     * @since 1.7.0
     */
    public function imageService(): ImageService;
}
