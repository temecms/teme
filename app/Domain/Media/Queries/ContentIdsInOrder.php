<?php

/**
 * Every content item's ID in order, for commands that walk all content a
 * chunk at a time.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Illuminate\Support\Facades\DB;

final class ContentIdsInOrder
{
    /**
     * Up to $limit content IDs after $lastId, trashed items included.
     *
     * @return list<string>
     */
    public static function after(?string $lastId, int $limit): array
    {
        return DB::table('contents')
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
