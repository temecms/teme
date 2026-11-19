<?php

/**
 * Where one media item sits for counting: its kind, and whether it is live
 * or in the trash. Operations take one before and one after a change.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Media\Models\Media;

final readonly class MediaState
{
    public function __construct(
        public string $kind,
        public string $bucket,
    ) {}

    /**
     * The state of the item as it is now.
     */
    public static function of(Media $media): self
    {
        return new self($media->kind, $media->trashed() ? 'trash' : 'live');
    }
}
