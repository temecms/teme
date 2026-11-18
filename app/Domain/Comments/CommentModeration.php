<?php

/**
 * Who looks after an item's comments (I-483, I-570): whoever holds comments.moderate,
 * on every item, and whoever may publish the item, so an author on their own
 * posts, and never a contributor. They moderate its comments, and
 * their own comments there are approved at once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CommentModeration
{
    /** The permission to look after every item's comments: editors' and administrators'. */
    public const string EVERY_ITEM = 'comments.moderate';

    /**
     * The gate ability that opens the Comments screen and its menu entry
     * (1.8c's D18): whoever looks after any item's comments. Not a
     * permission: it is derived, as I-570 derives the own-items right.
     */
    public const string LOOK_AFTER = 'look-after-comments';

    public static function allows(User $user, Content $item): bool
    {
        return $user->can(self::EVERY_ITEM) || Gate::forUser($user)->allows('publish', $item);
    }
}
