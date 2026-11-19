<?php

/**
 * A file was stored in the media library: a new upload, or a new file in place
 * of an item's old one.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Media;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A file was stored in the media library: a new upload, or a new file in
 * place of an item's old one. Sent after the change is saved, never during
 * an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'media.read')]
final readonly class MediaStored implements Notification
{
    /**
     * @param  MediaRef  $media  the media item, with its new file
     * @param  bool  $replaced  true when the file took the place of the item's old one
     * @param  string|null  $actorId  the account ID of the person who stored it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public MediaRef $media,
        public bool $replaced,
        public ?string $actorId,
    ) {}
}
