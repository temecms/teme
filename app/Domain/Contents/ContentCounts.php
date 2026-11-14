<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The exact status counts behind the list tabs (I-141). Maintained by every
 * content Operation inside its own transaction, so they cannot drift from the
 * rows; teme:counts:rebuild is the safety net.
 */
final class ContentCounts
{
    /** @var list<string> The status buckets, as ContentState names them. */
    private const array STATUS_BUCKETS = ['draft', 'review', 'scheduled', 'published', 'private', 'trash'];

    /** @var array<string, list<string>> The month rows each list tab sums, by bucket prefix (I-3131). */
    private const array TAB_MONTHS = [
        'all' => ['u:draft:', 'u:review:', 'u:scheduled:', 'u:published:', 'u:private:'],
        'mine' => ['u:draft:', 'u:review:', 'u:scheduled:', 'u:published:', 'u:private:'],
        'published' => ['p:'],
        'private' => ['u:private:'],
        'drafts' => ['u:draft:'],
        'review' => ['u:review:'],
        'scheduled' => ['u:scheduled:'],
        'trash' => ['u:trash:'],
    ];

    /**
     * Moves one item's count from its state before a change to its state
     * after. Either side may be null: created, or deleted for good.
     */
    public static function apply(?ContentState $before, ?ContentState $after): void
    {
        if ($before == $after) {
            return;
        }

        /** @var array<string, array{0: string, 1: string, 2: int, 3: int}> $deltas */
        $deltas = [];

        foreach ([[$before, -1], [$after, 1]] as [$state, $delta]) {
            if ($state === null) {
                continue;
            }

            foreach ($state->rows() as [$bucket, $authorId]) {
                $key = "{$state->type}|{$bucket}|{$authorId}";
                $deltas[$key] = [$state->type, $bucket, $authorId, ($deltas[$key][3] ?? 0) + $delta];
            }
        }

        // A fixed order, so two Operations touching the same rows lock them in
        // the same order and cannot deadlock.
        ksort($deltas);

        foreach ($deltas as [$type, $bucket, $authorId, $delta]) {
            if ($delta === 0) {
                continue;
            }

            DB::statement(
                'INSERT INTO content_counts (type, bucket, author_id, count) VALUES (?, ?, ?, ?)
                 ON CONFLICT (type, bucket, author_id) DO UPDATE SET count = content_counts.count + EXCLUDED.count',
                [$type, $bucket, $authorId, $delta],
            );
        }
    }

    /**
     * Moves one author's rows onto another, when DeleteUser reassigns content.
     */
    public static function moveAuthor(int $from, int $to): void
    {
        if ($from === $to || $from === 0) {
            return;
        }

        DB::statement(
            'INSERT INTO content_counts (type, bucket, author_id, count)
             SELECT type, bucket, ?, count FROM content_counts WHERE author_id = ?
             ORDER BY type, bucket
             ON CONFLICT (type, bucket, author_id) DO UPDATE SET count = content_counts.count + EXCLUDED.count',
            [$to, $from],
        );

        DB::table('content_counts')->where('author_id', $from)->delete();
    }

    /**
     * The counts for one type, by status bucket, never the months, for everyone
     * or for one author. Buckets with no items are left out.
     *
     * @return array<string, int>
     */
    public static function for(string $type, int $authorId = 0): array
    {
        return DB::table('content_counts')
            ->where('type', $type)
            ->where('author_id', $authorId)
            ->whereIn('bucket', self::STATUS_BUCKETS)
            ->where('count', '>', 0)
            ->orderBy('bucket')
            ->pluck('count', 'bucket')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * How many published items these types hold together, for everyone or
     * for one author: their `published` buckets summed in one statement; 0
     * for no types, with none.
     *
     * @param  list<string>  $types
     */
    public static function published(array $types, int $authorId = 0): int
    {
        if ($types === []) {
            return 0;
        }

        return (int) DB::table('content_counts')
            ->whereIn('type', $types)
            ->where('author_id', $authorId)
            ->where('bucket', 'published')
            ->sum('count');
    }

    /**
     * The months that hold a list tab's items, newest first, each with how
     * many it holds, for everyone or for one author: the month of each item's
     * last change, or on Published the month it was published (I-3131). An
     * unknown tab reads as All.
     *
     * @return list<array{month: string, count: int}>
     */
    public static function months(string $type, string $tab, int $authorId = 0): array
    {
        $prefixes = self::TAB_MONTHS[$tab] ?? self::TAB_MONTHS['all'];

        return DB::table('content_counts')
            ->where('type', $type)
            ->where('author_id', $authorId)
            ->where(function (Builder $query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('bucket', 'like', $prefix.'%');
                }
            })
            ->selectRaw('right(bucket, 7) AS month, sum(count) AS count')
            ->groupByRaw('right(bucket, 7)')
            ->havingRaw('sum(count) > 0')
            ->orderByDesc('month')
            ->get()
            ->map(fn (object $row): array => ['month' => (string) $row->month, 'count' => (int) $row->count])
            ->values()
            ->all();
    }

    /**
     * The month rows as the items give them, as SQL selecting type, bucket,
     * author_id and count, in this zone: the truth that teme:counts:rebuild
     * and a time zone's recount compare with and write (I-3131).
     */
    public static function monthTruth(string $zone): string
    {
        $at = DB::escape($zone);

        return <<<SQL
            WITH items AS (
                SELECT type, author_id,
                    CASE WHEN deleted_at IS NOT NULL THEN 'trash' WHEN status = 'published' AND visibility = 'private' THEN 'private' ELSE status END AS state,
                    to_char(updated_at AT TIME ZONE {$at}, 'YYYY-MM') AS updated_month,
                    to_char(published_at AT TIME ZONE {$at}, 'YYYY-MM') AS published_month
                FROM contents
            )
            SELECT type, 'u:' || state || ':' || updated_month AS bucket, 0 AS author_id, count(*) AS count FROM items WHERE updated_month IS NOT NULL GROUP BY 1, 2
            UNION ALL
            SELECT type, 'u:' || state || ':' || updated_month, author_id, count(*) FROM items WHERE updated_month IS NOT NULL AND author_id IS NOT NULL GROUP BY 1, 2, 3
            UNION ALL
            SELECT type, 'p:' || published_month, 0, count(*) FROM items WHERE state = 'published' AND published_month IS NOT NULL GROUP BY 1, 2
            SQL;
    }
}
