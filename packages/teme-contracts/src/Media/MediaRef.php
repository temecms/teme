<?php

/**
 * A media item as a hook tells of it: plain values read after the change was
 * saved, never the item itself.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Media;

/**
 * A media item as a hook tells of it: plain values read after the change
 * was saved, never the item itself.
 *
 * @since 0.5.0
 */
final readonly class MediaRef
{
    /**
     * @param  string  $id  the media item's ID, an upper-case ULID
     * @param  string  $kind  what kind of file it is, such as image, video, audio or document
     * @param  string  $mimeType  its file's MIME type, such as image/png
     * @param  string  $filename  the name it was uploaded with
     * @param  string  $title  its title
     * @param  int  $sizeBytes  its file's size in bytes
     * @param  int|null  $width  its width in pixels, or null when it has none
     * @param  int|null  $height  its height in pixels, or null when it has none
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $mimeType,
        public string $filename,
        public string $title,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
    ) {}
}
