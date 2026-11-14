<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The scheduled publications of one content type that are held, waiting for
 * someone to reschedule them, by reason. It reads only.
 */
final class HeldSchedules
{
    /**
     * How many of the type's pending schedules are held: in all, because the
     * scheduler's account was removed, and because they may no longer publish.
     *
     * @return array{count: int, removed: int, cannotPublish: int}
     */
    public static function forType(string $type): array
    {
        $counts = DB::table('scheduled_publications')
            ->join('contents', 'contents.id', '=', 'scheduled_publications.content_id')
            ->where('contents.type', $type)
            ->whereNull('scheduled_publications.completed_at')
            ->whereNull('scheduled_publications.failed_at')
            ->whereNull('scheduled_publications.cancelled_at')
            ->whereNotNull('scheduled_publications.held_reason')
            ->groupBy('scheduled_publications.held_reason')
            ->selectRaw('scheduled_publications.held_reason AS reason, count(*) AS n')
            ->pluck('n', 'reason');

        $removed = (int) ($counts['scheduler_removed'] ?? 0);
        $cannotPublish = (int) ($counts['scheduler_cannot_publish'] ?? 0);

        return ['count' => $removed + $cannotPublish, 'removed' => $removed, 'cannotPublish' => $cannotPublish];
    }
}
