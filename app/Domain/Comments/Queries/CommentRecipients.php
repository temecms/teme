<?php

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentModeration;
use App\Domain\Contents\Models\Content;
use App\Domain\Notices\Queries\NoticeChoices;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TypePermissions;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a comment's emails go to (1.8d's D7, D8), read when the email is sent,
 * never when it was queued: whoever looks after the item's comments for the
 * moderators' email, the item's author for theirs. Only verified accounts
 * that aren't suspended, never the people the caller leaves out, and never
 * someone who turned the email off.
 */
final class CommentRecipients
{
    /**
     * Everyone who looks after the item's comments now, by
     * CommentModeration::allows(), less $leaveOut and those who chose not to get $kind's email, in ID order.
     *
     * @param  list<int>  $leaveOut  user IDs
     *
     * @return list<User>
     */
    public function moderators(Content $item, array $leaveOut, string $kind): array
    {
        $type = app(ContentTypeRegistry::class)->get($item->type);

        // The candidates in SQL, so a site with many authors reads few rows:
        // comments.moderate holders, holders of both the type's publish and
        // edit.any, and the item's author. An ability Spatie's list doesn't
        // have yet is left out, since Spatie throws for it (I-413). Then the
        // Operations' own rule for each, so this can't drift from who may
        // moderate.
        $publish = $type->ability('publish');
        $editAny = $type->ability('edit.any');
        $present = TypePermissions::findable(array_values(array_unique([$publish, $editAny])));

        $candidates = User::query()
            ->where(function (Builder $who) use ($item, $publish, $editAny, $present): Builder {
                $who->whereIn('id', User::permission(CommentModeration::EVERY_ITEM)->select('id'));

                if (in_array($publish, $present, true) && in_array($editAny, $present, true)) {
                    $who->orWhere(fn (Builder $both): Builder => $both
                        ->whereIn('id', User::permission($publish)->select('id'))
                        ->whereIn('id', User::permission($editAny)->select('id')));
                }

                return $who->orWhere('id', $item->author_id);
            })
            ->orderBy('id')
            ->get();

        return array_values($candidates
            ->filter(fn (User $person): bool => self::reachable($person, $leaveOut, $kind) && CommentModeration::allows($person, $item))
            ->all());
    }

    /**
     * The item's author, when they can be emailed and want $kind's
     * email, or null.
     *
     * @param  list<int>  $leaveOut  user IDs
     */
    public function author(Content $item, array $leaveOut, string $kind): ?User
    {
        $author = $item->author_id === null ? null : User::query()->find($item->author_id);

        return $author !== null && self::reachable($author, $leaveOut, $kind) ? $author : null;
    }

    /**
     * @param  list<int>  $leaveOut
     */
    private static function reachable(User $person, array $leaveOut, string $kind): bool
    {
        return $person->email_verified_at !== null
            && $person->suspended_at === null
            && ! in_array((int) $person->id, $leaveOut, true)
            && app(NoticeChoices::class)->wants($person, $kind);
    }
}
