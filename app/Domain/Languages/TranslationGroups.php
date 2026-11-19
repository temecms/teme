<?php

/**
 * Translation groups (I-3360): the lower-case ULID every language version of
 * one item shares, minted fresh, never an item's own ID, never shown to a
 * reader.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TranslationGroups
{
    /**
     * A new group's ID: a lower-case ULID.
     */
    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * The IDs of the group's items, live only unless the trash is asked
     * for too, in ID order.
     *
     * @return list<string>
     */
    public static function memberIds(string $group, bool $withTrashed = false): array
    {
        return DB::table('contents')
            ->where('translation_group', $group)
            ->when(! $withTrashed, fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
