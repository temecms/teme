<?php

declare(strict_types=1);

/*
 * docs/architecture/media.md, "Storage" and "Variants". Where media lives is
 * the operator's environment (I-3456): MEDIA_STORAGE names a location, which
 * gives both roles their disks; MEDIA_DISK and MEDIA_ORIGINALS_DISK override
 * one role each, for a site that keeps originals on the server.
 */
$locations = [
    'server' => ['public' => 'media', 'originals' => 'media-originals'],
    'bucket' => ['public' => 'media-bucket', 'originals' => 'media-originals-bucket'],
];

$storage = (string) env('MEDIA_STORAGE', 'server');

return [
    'locations' => $locations,

    // An unknown MEDIA_STORAGE names a disk that doesn't exist, so its first use fails loudly.
    'disk' => env('MEDIA_DISK') ?: ($locations[$storage]['public'] ?? "unknown-media-storage-{$storage}"),

    'originals_disk' => env('MEDIA_ORIGINALS_DISK') ?: ($locations[$storage]['originals'] ?? "unknown-media-storage-{$storage}"),

    // gd or imagick. GD cannot read HEIC or HEIF.
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),

    // How long a signed address to an original in a bucket works.
    'original_link_seconds' => 300,
];
