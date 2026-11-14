<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Media\MediaVariants;
use Illuminate\Support\Facades\DB;

/**
 * The images whose variants were made with an older set, in ID order, for
 * teme:media:regenerate to page through. Uses the (variant_set, id) index.
 */
final class ImagesNeedingVariants
{
    /**
     * Up to $limit image IDs after $lastId whose variant set is older than
     * the current one.
     *
     * @return list<string>
     */
    public static function after(?string $lastId, int $limit): array
    {
        return DB::table('media')
            ->where('kind', 'image')
            ->where('variant_set', '<', MediaVariants::SET)
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
