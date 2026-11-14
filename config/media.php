<?php

declare(strict_types=1);

/*
 * docs/architecture/media.md, "Storage" and "Variants". The disks are named
 * here so a site can point them at object storage without code changes.
 */
return [
    'disk' => env('MEDIA_DISK', 'media'),

    'originals_disk' => env('MEDIA_ORIGINALS_DISK', 'media-originals'),

    // gd or imagick. GD cannot read HEIC or HEIF.
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),
];
