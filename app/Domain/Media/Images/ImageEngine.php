<?php

/**
 * Teme's own seam around the image library (study K decision 3A), so the
 * library or its driver can change without touching an Operation or a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Images;

interface ImageEngine
{
    /**
     * Whether the configured driver can read images of this media type.
     */
    public function canDecode(string $mimeType): bool;

    /**
     * The image's size and whether it moves, or null when the driver can't
     * read it, such as a damaged file whose header still names an image.
     *
     * @return array{width: int, height: int, animated: bool}|null
     */
    public function inspect(string $path): ?array;

    /**
     * Writes a WebP variant at each width, never enlarged, and a square
     * thumbnail cropped around the focal point, into $directory. Decodes the
     * source once.
     *
     * @param  list<int>  $widths
     * @param  array<string, array{0: int, 1: int}>  $share  the share images to make too, by key, each width × height (I-700)
     *
     * @return array<string, array{file: string, width: int, height: int, bytes: int}> keyed w<width>, thumb and each share key
     */
    public function variants(string $source, array $widths, int $thumbnail, int $focalX, int $focalY, string $directory, array $share = []): array;

    /**
     * Decodes an image from its bytes and writes it again as a WebP still,
     * its longer side at most $longestSide pixels (never enlarged), at this
     * quality; null when the bytes aren't an image the driver can read.
     *
     * @return array{bytes: string, width: int, height: int}|null
     */
    public function reencode(string $bytes, int $longestSide, int $quality): ?array;
}
