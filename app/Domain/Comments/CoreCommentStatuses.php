<?php

/**
 * Core's comment statuses (I-224, docs/studies/comments.md §3.8): pending,
 * approved, spam and trash, which moderators move comments between, and
 * removed, a tombstone's, which only deleting a comment with replies makes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

final class CoreCommentStatuses
{
    public static function register(CommentStatusRegistry $registry): void
    {
        $registry->register(new CommentStatus(CommentStatus::PENDING, 'Pending', shown: false, settable: true, deletable: false));
        $registry->register(new CommentStatus(CommentStatus::APPROVED, 'Approved', shown: true, settable: true, deletable: false));
        $registry->register(new CommentStatus(CommentStatus::SPAM, 'Spam', shown: false, settable: true, deletable: true));
        $registry->register(new CommentStatus(CommentStatus::TRASH, 'Trash', shown: false, settable: true, deletable: true));
        // Its place in the thread is shown, and nothing else (I-278).
        $registry->register(new CommentStatus(CommentStatus::REMOVED, 'Removed', shown: true, settable: false, deletable: false));
    }
}
