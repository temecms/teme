<?php

/**
 * A file was moved to the trash.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Media;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A file was moved to the trash. Its files stay, so restoring it brings every use back. Sent after the change is saved, never during an import run.
 *
 * @since next
 */
#[HookContract(capability: 'media.read')]
final readonly class MediaTrashed implements Notification
{
    /**
     * @param  MediaRef  $media  the media item, as it is after the change
     * @param  string|null  $actorId  the account ID of the person who trashed it, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public MediaRef $media,
        public ?string $actorId,
    ) {}
}
