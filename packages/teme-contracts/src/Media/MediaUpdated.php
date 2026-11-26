<?php

/**
 * A file's details were changed: its title, alt text, caption, credit, licence or focal point.
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
 * A file's details were changed: its title, alt text, caption, credit, licence or focal point. Sent after the change is saved. An import run does not send it yet.
 *
 * @since next
 */
#[HookContract(capability: 'media.read')]
final readonly class MediaUpdated implements Notification
{
    /**
     * @param  MediaRef  $media  the media item, as it is after the change
     * @param  string|null  $actorId  the account ID of the person who changed it, or null when no signed-in person did
     *
     * @since next
     */
    public function __construct(
        public MediaRef $media,
        public ?string $actorId,
    ) {}
}
