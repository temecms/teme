<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Media in the trash, for the daily purge.
 */
final class TrashedMedia
{
    /**
     * Up to $limit IDs of items trashed before $cutoff, oldest first, on the
     * media_trash index.
     *
     * @return list<string>
     */
    public function olderThan(CarbonInterface $cutoff, int $limit): array
    {
        return DB::table('media')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
