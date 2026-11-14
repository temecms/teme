<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An image a template draws with `<x-teme::image>`: the media item, the alt
 * text for this use (null for the item's own), whether it is decorative, and
 * its caption and credit, when known.
 */
final readonly class ImageRef
{
    /**
     * @param  string  $id  the media item's ID, which `<x-teme::image>` takes
     * @param  string|null  $alt  the alt text for this use; null for the media item's own
     * @param  bool  $decorative  whether the image is decoration, drawn with empty alt text
     * @param  string|null  $caption  its caption, when known
     * @param  string|null  $credit  its credit, when known
     */
    public function __construct(
        public string $id,
        public ?string $alt,
        public bool $decorative,
        public ?string $caption = null,
        public ?string $credit = null,
    ) {}
}
