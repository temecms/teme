<?php

/**
 * The image a page's social card shows (I-293): its media item, the absolute
 * address of the file served, its size and alt text, and whether that file
 * is the 1200×630 card or the largest width serving until the card is made
 * (I-855).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Head;

final readonly class SocialImage
{
    public function __construct(
        public string $mediaId,
        public string $url,
        public int $width,
        public int $height,
        public ?string $alt,
        public bool $card,
    ) {}
}
