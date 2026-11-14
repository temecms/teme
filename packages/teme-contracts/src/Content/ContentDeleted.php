<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An item was deleted for good, from the trash. Only its ID, type and title
 * are left to tell of it. Sent after the change is saved, never during an
 * import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentDeleted implements Notification
{
    /**
     * @param  string  $id  the ID the item had
     * @param  string  $type  its content type's key
     * @param  string  $title  the title it had
     * @param  string|null  $actorId  the account ID of the person who deleted it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public ?string $actorId,
    ) {}
}
