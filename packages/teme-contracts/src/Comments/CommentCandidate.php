<?php

/**
 * A new comment as its moderator is shown it: where it was written, its words
 * as the writer typed them and how many links they hold, who wrote it and from
 * where, and whether an approved comment of theirs exists already (the same
 * account, or the same email address from the same browser).
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Comments;

/**
 * A new comment as its moderator is shown it: where it was written, its words
 * as the writer typed them and how many links they hold, who wrote it and
 * from where, and whether an approved comment of theirs exists already (the
 * same account, or the same email address from the same browser).
 *
 * @since 0.1.0
 */
final readonly class CommentCandidate
{
    /**
     * @param  string  $contentId  the item commented on
     * @param  string|null  $parentId  the comment it replies to, or null
     * @param  string  $text  the words, as plain text
     * @param  int  $links  the web addresses linked in them
     * @param  string|null  $authorUserId  the account, when its writer is signed in
     * @param  string|null  $itemUrl  the item's public address
     * @param  array<string, string>  $signals  hidden form fields the comment form carried, by name; always empty in this release, as the hidden field comes later (issue 805)
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $contentId,
        public ?string $parentId,
        public string $text,
        public int $links,
        public string $authorName,
        public ?string $authorEmail,
        public ?string $authorUrl,
        public ?string $authorUserId,
        public ?string $ip,
        public ?string $userAgent,
        public bool $returning,
        public ?string $itemUrl,
        public array $signals = [],
    ) {}
}
