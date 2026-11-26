<?php

/**
 * A file was taken out of the trash and is live again.
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
 * A file was taken out of the trash and is live again. Sent after the change is saved, never during an import run.
 *
 * @since next
 */
#[HookContract(capability: 'media.read')]
final readonly class MediaRestored implements Notification
{
    /**
     * @param  MediaRef  $media  the media item, as it is after the change
     * @param  string|null  $actorId  the account ID of the person who restored it, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public MediaRef $media,
        public ?string $actorId,
    ) {}
}
