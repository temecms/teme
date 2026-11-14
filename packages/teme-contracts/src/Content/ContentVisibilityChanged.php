<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * Who may read an item changed: it was made private, protected by a
 * password, or public again. A private item has no live address, and one
 * made public again gets it back. Never carries the password. Sent after
 * the change is saved, never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentVisibilityChanged implements Notification
{
    /**
     * @param  ItemRef  $item  the item, as it is after the change
     * @param  string  $from  its visibility before. Today one of: public, private or protected; later releases may add others, so a plugin treats a value it does not know as it would an unknown visibility
     * @param  string  $to  its visibility now. Today one of: public, private or protected; later releases may add others, so a plugin treats a value it does not know as it would an unknown visibility
     * @param  string|null  $formerUrl  the live address it had, or null when it had none
     * @param  string|null  $actorId  the account ID of the person who changed it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public ItemRef $item,
        public string $from,
        public string $to,
        public ?string $formerUrl,
        public ?string $actorId,
    ) {}
}
