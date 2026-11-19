<?php

/**
 * What inspecting an uploaded file found: its detected type, kind and
 * extension, its size and fingerprint, and an image's dimensions.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

final readonly class InspectedFile
{
    public function __construct(
        public string $mimeType,
        public string $kind,
        public string $extension,
        public int $sizeBytes,
        public string $sha256,
        public ?int $width,
        public ?int $height,
        public bool $animated,
    ) {}
}
