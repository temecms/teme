<?php

/**
 * Autosaves nobody has come back to, for the sweep that keeps them as
 * revisions.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class AbandonedAutosaves
{
    /**
     * The IDs of up to $limit autosaves last saved before $cutoff, oldest
     * first.
     *
     * @return list<string>
     */
    public function olderThan(CarbonInterface $cutoff, int $limit): array
    {
        return DB::table('content_autosaves')
            ->where('saved_at', '<', $cutoff)
            ->orderBy('saved_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
