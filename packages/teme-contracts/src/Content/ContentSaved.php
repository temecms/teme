<?php

/**
 * An item's draft was saved as a new revision: when it was created, and each
 * time a person saved it, restored an older revision or checkpointed it.
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
 * An item's draft was saved as a new revision: when it was created, and each
 * time a person saved it, restored an older revision or checkpointed it. Not
 * sent for an autosave, which is never a revision, nor for a change of its
 * address or date alone. Sent after the change is saved, never during an
 * import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class ContentSaved implements Notification
{
    /**
     * @param  ItemRef  $item  the item, as it is after the save
     * @param  string  $revisionId  the new working revision's ID
     * @param  bool  $created  true when this save created the item
     * @param  string|null  $actorId  the account ID of the person who saved it, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public ItemRef $item,
        public string $revisionId,
        public bool $created,
        public ?string $actorId,
    ) {}
}
