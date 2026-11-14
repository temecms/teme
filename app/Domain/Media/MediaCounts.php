<?php

declare(strict_types=1);

namespace App\Domain\Media;

use Illuminate\Support\Facades\DB;

/**
 * The exact counts behind the library's tabs, maintained by every media
 * Operation inside its transaction, as ContentCounts is for content (I-141).
 */
final class MediaCounts
{
    /**
     * Moves one item from its state before a change to its state after.
     * Either side may be null: uploaded, or deleted for good.
     */
    public static function apply(?MediaState $before, ?MediaState $after): void
    {
        if ($before == $after) {
            return;
        }

        $deltas = [];

        foreach ([[$before, -1], [$after, 1]] as [$state, $delta]) {
            if ($state !== null) {
                $key = "{$state->kind}|{$state->bucket}";
                $deltas[$key] = ($deltas[$key] ?? 0) + $delta;
            }
        }

        // A fixed order, so two Operations cannot deadlock.
        ksort($deltas);

        foreach ($deltas as $key => $delta) {
            if ($delta === 0) {
                continue;
            }

            [$kind, $bucket] = explode('|', $key);

            DB::statement(
                'INSERT INTO media_counts (kind, bucket, count) VALUES (?, ?, ?)
                 ON CONFLICT (kind, bucket) DO UPDATE SET count = media_counts.count + EXCLUDED.count',
                [$kind, $bucket, $delta],
            );
        }
    }

    /**
     * Every count above zero, by kind and bucket.
     *
     * @return array<string, array<string, int>>
     */
    public static function all(): array
    {
        $counts = [];

        foreach (DB::table('media_counts')->where('count', '>', 0)->orderBy('kind')->orderBy('bucket')->get() as $row) {
            $counts[(string) $row->kind][(string) $row->bucket] = (int) $row->count;
        }

        return $counts;
    }
}
