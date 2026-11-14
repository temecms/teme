<?php

declare(strict_types=1);

namespace Teme\Contracts\Comments;

use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A comment arrived, or its status changed: approved, held, marked as spam
 * or trashed by a moderator, or posted as a reply from the admin. Never
 * carries the comment's text or anything about who wrote it. Sent after the
 * change is saved, never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract(capability: 'content.read')]
final readonly class CommentStatusChanged implements Notification
{
    /**
     * @param  string  $commentId  the comment's ID
     * @param  ItemRef  $item  the item it is on
     * @param  string|null  $from  its status before, or null for a new comment. Today one of: pending, approved, spam or trash; later releases may add others, so a plugin treats a value it does not know as it would an unknown status, and never matches the list exhaustively.
     * @param  string  $to  its status now. Today one of: pending, approved, spam or trash; later releases may add others, so a plugin treats a value it does not know as it would an unknown status, and never matches the list exhaustively.
     * @param  string|null  $actorId  the account ID of the person who changed it, or null when no signed-in person did, such as a guest commenting
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $commentId,
        public ItemRef $item,
        public ?string $from,
        public string $to,
        public ?string $actorId,
    ) {}
}
