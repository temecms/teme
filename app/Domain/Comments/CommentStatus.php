<?php

/**
 * One comment status (I-224): its key, as stored in `comments.status`; its
 * label; whether readers see a comment in it, an approved one or a
 * tombstone's place; whether a moderator may move a comment to it; and
 * whether Delete permanently is offered from it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

final readonly class CommentStatus
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string SPAM = 'spam';

    public const string TRASH = 'trash';

    public const string REMOVED = 'removed';

    /**
     * The statuses a thread shows, as its indexes' predicates name them word
     * for word (comments_thread, comments_conversations): approved comments,
     * and tombstones in their places (I-278). The registry's shown() says the
     * same of core's five; SQL is written with these.
     *
     * @var list<string>
     */
    public const array THREAD = [self::APPROVED, self::REMOVED];

    public function __construct(
        public string $key,
        public string $label,
        public bool $shown,
        public bool $settable,
        public bool $deletable,
    ) {}
}
