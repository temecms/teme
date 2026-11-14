<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lock on a translation group's rows (I-3360): an Operation that changes
 * a group's members, or a member's language, takes it as its first row
 * lock, after the languages lock and a hierarchical type's tree lock.
 */
final class GroupLock
{
    /**
     * Locks the named items and every member of their translation groups, live and trashed,
     * in ID order, so two Operations on one group queue rather than deadlock
     * (I-3360). Call it in perform() after the languages lock and a hierarchical
     * type's tree lock, as the first row lock.
     *
     * @param  list<string>  $contentIds
     *
     * @return Collection<string, Content> the locked rows, keyed by ID
     */
    public static function lock(array $contentIds): Collection
    {
        $groups = DB::table('contents')->whereIn('id', $contentIds)->whereNotNull('translation_group')->distinct()->pluck('translation_group')->map(fn (mixed $group): string => (string) $group)->all();

        /** @var Collection<string, Content> $rows */
        $rows = Content::withTrashed()
            ->where(fn ($query) => $query->whereIn('id', $contentIds)->when($groups !== [], fn ($query) => $query->orWhereIn('translation_group', $groups)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // A named item joined another group between the read and the lock. Locking that group now would take
        // rows out of ID order and could deadlock, and an Operation's transaction is tried once, so refuse instead.
        $now = $rows->only($contentIds)->pluck('translation_group')->filter()->unique()->values()->all();

        if (array_diff($now, $groups) !== []) {
            throw ValidationException::withMessages(['translations' => __('Someone changed these translations while you were working. Try again.')]);
        }

        return $rows;
    }
}
