<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The months that hold files, for the library's and the modal's Date select:
 * the site's months, newest first, as the content lists bound theirs, found
 * without reading the library (I-1316).
 */
final class MediaMonths
{
    /**
     * Returns each month on the clock of $zone that holds a live file, or
     * with $trash a file in the trash, newest first, as its value ("2026-10")
     * and its words ("October 2026"); and the chosen month too, when it is a
     * real month the list lacks, so the select never shows a value it
     * doesn't offer.
     *
     * @return list<array{value: string, label: string}>
     */
    public function list(bool $trash, string $zone, ?string $chosen = null): array
    {
        $months = $trash ? $this->trashed($zone) : $this->live($zone);

        if ($chosen !== null && preg_match(MediaLibraryRequest::MONTH, $chosen) === 1 && ! in_array($chosen, $months, true)) {
            $months[] = $chosen;
        }

        rsort($months);

        return array_map(
            fn (string $month): array => ['value' => $month, 'label' => CarbonImmutable::parse($month.'-01', 'UTC')->format('F Y')],
            $months,
        );
    }

    /**
     * The live library's months: the first live file, then the first at or
     * after the start of the next month on the site's clock, and so on, each
     * one probe of media_live_created, so the cost grows with the months,
     * never the files.
     *
     * @return list<string>
     */
    private function live(string $zone): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE months (month) AS (
                SELECT date_trunc('month', (SELECT min(created_at) FROM media WHERE deleted_at IS NULL) AT TIME ZONE CAST(? AS text))
                UNION ALL
                SELECT date_trunc('month', (
                    SELECT min(m.created_at)
                    FROM media m
                    WHERE m.deleted_at IS NULL
                      AND m.created_at >= (months.month + interval '1 month') AT TIME ZONE CAST(? AS text)
                ) AT TIME ZONE CAST(? AS text))
                FROM months
                WHERE months.month IS NOT NULL
            )
            SELECT to_char(month, 'YYYY-MM') AS month FROM months WHERE month IS NOT NULL
            SQL, [$zone, $zone, $zone]);

        return array_values(collect($rows)->pluck('month')->map(fn (mixed $month): string => (string) $month)->all());
    }

    /**
     * The trash's months on the site's clock, read from the trash itself,
     * which the purge keeps to the days trash is kept for.
     *
     * @return list<string>
     */
    private function trashed(string $zone): array
    {
        $months = DB::table('media')
            ->whereNotNull('deleted_at')
            ->selectRaw("DISTINCT to_char(created_at AT TIME ZONE CAST(? AS text), 'YYYY-MM') AS month", [$zone])
            ->pluck('month')
            ->map(fn (mixed $month): string => (string) $month)
            ->all();

        return array_values($months);
    }
}
