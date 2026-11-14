<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Comments\Models\Comment;

/**
 * The returning-commenter rule (docs/studies/comments.md §3.4, I-275): a
 * signed-in writer returns when their account has an approved comment; a
 * guest, when an approved comment has the same email address and came from
 * the same browser, whose token only that browser holds. A name and email
 * address alone, which anyone can type, are never enough.
 */
final class ReturningCommenter
{
    public static function isReturning(?string $email, ?string $tokenHash, ?int $userId): bool
    {
        if ($userId !== null) {
            return Comment::query()->where('author_user_id', $userId)->where('status', CommentStatus::APPROVED)->exists();
        }

        return $email !== null && $tokenHash !== null
            && Comment::query()->where('author_email', $email)->where('commenter_token_hash', $tokenHash)->where('status', CommentStatus::APPROVED)->exists();
    }
}
