<?php

/**
 * An item was taken out of the trash, and is back where it was before it was
 * trashed.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * An item was taken out of the trash, and is back where it was before it was
 * trashed. Not sent for an item that wasn't in the trash. Sent after the
 * change is saved, never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentRestored implements Notification
{
    /**
     * @param  ItemRef  $item  the item, as it is after the restore
     * @param  string|null  $actorId  the account ID of the person who restored it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public ItemRef $item,
        public ?string $actorId,
    ) {}
}
