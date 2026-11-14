<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

/**
 * A feed item's featured image as a format writes it: an absolute address, its media type when known, and its size.
 */
final readonly class FeedImage
{
    public function __construct(
        public string $url,
        public ?string $type,
        public int $width,
        public int $height,
    ) {}
}
