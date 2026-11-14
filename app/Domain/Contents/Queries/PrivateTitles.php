<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Visibility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An item's title as one person may see it in the admin (I-1700): a
 * private item's only for whoever may edit it, as ContentPolicy::update()
 * says through ContentAbilities; "A private item" for anyone else.
 */
final class PrivateTitles
{
    /**
     * The title as this person may see it, from what the caller read of the
     * item: its type, author and visibility.
     */
    public static function shown(string $title, string $type, ?int $authorId, string $visibility, ?User $viewer): string
    {
        if ($visibility !== Visibility::Private->value) {
            return $title;
        }

        $can = ContentAbilities::forUser($viewer, $type);
        $mayEdit = $can->editAny || ($can->editOwn && $viewer !== null && $authorId !== null && $authorId === (int) $viewer->getKey());

        return $mayEdit ? $title : __('A private item');
    }

    /**
     * The same for one item by its ID, for a caller that holds only its
     * title: one statement. An item that is gone keeps the title given.
     */
    public static function of(string $contentId, string $title, ?User $viewer): string
    {
        $row = DB::table('contents')->where('id', $contentId)->first(['type', 'author_id', 'visibility']);

        return $row === null ? $title : self::shown($title, (string) $row->type, $row->author_id === null ? null : (int) $row->author_id, (string) $row->visibility, $viewer);
    }
}
