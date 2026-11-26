<?php

/**
 * A file was deleted for good, after it had been in the trash.
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
 * A file was deleted for good, after it had been in the trash. Its payload is the file as it was before its row went. Sent after the change is saved, never during an import run.
 *
 * @since next
 */
#[HookContract(capability: 'media.read')]
final readonly class MediaDeleted implements Notification
{
    /**
     * @param  MediaRef  $media  the media item, as it was before its row went
     * @param  string|null  $actorId  the account ID of the person who deleted it, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public MediaRef $media,
        public ?string $actorId,
    ) {}
}
