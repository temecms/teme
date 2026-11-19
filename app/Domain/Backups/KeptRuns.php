<?php

/**
 * Which of a place's backups are kept (I-361), as the backups settings word
 * it: the newest of each of the last keep_daily days that have one; then,
 * from the backups before those, the newest of each of keep_weekly weeks;
 * then, from the backups before those, the newest of each of keep_monthly
 * months; days, weeks and months in the site's time zone. Only complete
 * runs are kept.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Tools\SnapshotRun;

final class KeptRuns
{
    /**
     * The kept runs' IDs, newest first.
     *
     * @param  list<SnapshotRun>  $runs
     *
     * @return list<string>
     */
    public static function choose(array $runs, int $daily, int $weekly, int $monthly, string $zone): array
    {
        $complete = array_values(array_filter($runs, fn (SnapshotRun $run): bool => $run->complete()));
        usort($complete, fn (SnapshotRun $a, SnapshotRun $b): int => [$b->time, $b->runId] <=> [$a->time, $a->runId]);

        $kept = [];
        $next = 0;

        // Each tier starts where the one before stopped: "then one from each of this many weeks before them".
        foreach ([[$daily, 'Y-m-d'], [$weekly, 'o-\WW'], [$monthly, 'Y-m']] as [$count, $format]) {
            $buckets = [];

            while ($next < count($complete)) {
                $run = $complete[$next];
                $bucket = $run->time->setTimezone($zone)->format($format);

                if (! array_key_exists($bucket, $buckets)) {
                    if (count($buckets) >= $count) {
                        break;
                    }

                    $buckets[$bucket] = true;
                    $kept[] = $run->runId;
                }

                $next++;
            }
        }

        return $kept;
    }
}
