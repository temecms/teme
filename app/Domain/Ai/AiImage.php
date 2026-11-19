<?php

/**
 * An image sent with a request, as bytes, never a link (I-319): a draft's
 * media is private, so the provider gets a copy. Raster only, 4 MB at most.
 * Made in the job that asks, never queued: the job carries IDs.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

final readonly class AiImage
{
    public const int MAX_BYTES = 4 * 1024 * 1024;

    /** The raster types a provider is sent (I-319); never SVG, PDF or video. */
    public const array TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(public string $mediaType, public string $bytes)
    {
        if (! in_array($mediaType, self::TYPES, true)) {
            throw new InvalidArgumentException("An AI image must be JPEG, PNG, WebP or GIF, not [{$mediaType}].");
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('An AI image is larger than 4 MB.');
        }
    }

    public function base64(): string
    {
        return base64_encode($this->bytes);
    }
}
