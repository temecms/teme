<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A published item was taken off the site and went back to being a draft.
 * Sent after the change is saved, never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentUnpublished implements Notification
{
    /**
     * @param  ItemRef  $item  the item, now with no live address
     * @param  string|null  $formerUrl  the live address it had, or null when it had none
     * @param  string|null  $actorId  the account ID of the person who unpublished it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public ItemRef $item,
        public ?string $formerUrl,
        public ?string $actorId,
    ) {}
}
