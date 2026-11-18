<?php

/**
 * Where a reply goes in its thread (I-272; 1.8c′'s D12): under the comment it
 * answers, one level deeper, or beside it, under its ancestor, when it sits
 * at the deepest level the settings allow or below it. One rule for the
 * form's replies and the admin's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Comments\Models\Comment;

final class CommentPlace
{
    /**
     * The reply's parent's ID and path, and its own depth, for a reply to
     * $answered in a thread at most $deepest levels deep; the ancestors are
     * read from the path.
     *
     * @return array{0: string|null, 1: string, 2: int}
     */
    public static function under(Comment $answered, int $deepest): array
    {
        $depth = min($answered->depth, $deepest - 1);

        if ($depth < 1) {
            return [null, '', 1];
        }

        $ancestors = array_slice(explode('/', rtrim($answered->path, '/')), 0, $depth);

        return [$ancestors[$depth - 1], implode('/', $ancestors).'/', $depth + 1];
    }
}
