<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The one item of a type with one item only (I-415): its ID, its author and
 * whether it is in the trash; null while it has none. It reads only.
 */
final class SingleItem
{
    /**
     * The type's one item, the live one before a trashed one and the earlier
     * one first, or null while the type has none.
     *
     * @return array{id: string, authorId: int, trashed: bool}|null
     */
    public static function of(string $type): ?array
    {
        $row = DB::table('contents')
            ->where('type', $type)
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('created_at')
            ->first(['id', 'author_id', 'deleted_at']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'authorId' => (int) $row->author_id,
            'trashed' => $row->deleted_at !== null,
        ];
    }
}
