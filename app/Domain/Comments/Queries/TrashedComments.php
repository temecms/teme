<?php

/**
 * Finds trashed comments for the daily purge (1.8c′'s D8): those in the
 * trash since the cut-off or before, deepest first, so a reply always goes
 * before the comment it answers.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TrashedComments
{
    /**
     * Up to $limit comments in the trash since $cutoff or before, deepest
     * first and then by ID, each after $after, the last one the caller read:
     * a comment the purge couldn't delete is never read twice.
     *
     * @param  array{id: string, depth: int}|null  $after
     *
     * @return list<array{id: string, depth: int}>
     */
    public function olderThan(CarbonInterface $cutoff, int $limit, ?array $after = null): array
    {
        $query = DB::table('comments')
            ->where('status', CommentStatus::TRASH)
            ->where('status_changed_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('depth')
            ->orderBy('id')
            ->limit($limit);

        if ($after !== null) {
            $query->where(fn (Builder $next): Builder => $next
                ->where('depth', '<', $after['depth'])
                ->orWhere(fn (Builder $same): Builder => $same->where('depth', $after['depth'])->where('id', '>', $after['id'])));
        }

        return $query->get(['id', 'depth'])
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'depth' => (int) $row->depth])
            ->values()
            ->all();
    }
}
