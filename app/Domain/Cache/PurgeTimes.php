<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Recent purges, for the race guard (I-264): a page whose drawing began
 * before a purge of one of its tags may show what the purge removed, so it
 * is never stored after it. PostgreSQL numbers each purge as it is noted,
 * so "before" is the order they were noted in, whichever server noted them
 * and whatever its clock said (slice 1.7d). Kept in `page_cache_purges`
 * only as long as a page may take to draw (PageCache::RENDER_WINDOW).
 */
final readonly class PurgeTimes
{
    /** Rows written per statement. */
    private const int BATCH = 500;

    /**
     * `page_cache_purges`' columns, in the order a row's values are given: a
     * row written as an array keyed by the column's own name reads, to
     * NoTypeLiteralsTest, as a type named in code (B-225).
     */
    private const array COLUMNS = ['tag', 'purged_at'];

    /**
     * Notes that these tags were purged, each row numbered by PostgreSQL as
     * it is written and stamped with this moment, for the prune.
     *
     * @param  list<string>  $tags
     */
    public function record(array $tags, CarbonInterface $at): void
    {
        $when = self::at($at);

        foreach (array_chunk(array_values(array_unique($tags)), self::BATCH) as $chunk) {
            DB::table('page_cache_purges')->insert(
                array_map(fn (string $tag): array => array_combine(self::COLUMNS, [$tag, $when]), $chunk),
            );
        }
    }

    /**
     * The number of the latest purge noted, or 0 when none is kept. A
     * drawing reads it as it begins (PageCache::beginDrawing()): a purge is
     * noted only once its change has committed, and a sequence numbers in
     * the order it is asked, so every purge numbered at or below it
     * belongs to a change the drawing can already read.
     */
    public function latest(): int
    {
        return (int) DB::table('page_cache_purges')->max('id');
    }

    /**
     * Whether any of these tags, or every page (`*`), was purged after the
     * purge numbered $after.
     *
     * @param  list<string>  $tags
     */
    public function since(array $tags, int $after): bool
    {
        return DB::table('page_cache_purges')
            ->whereIn('tag', [...$tags, PageTags::EVERYTHING])
            ->where('id', '>', $after)
            ->exists();
    }

    /**
     * Forgets the purges stamped before this moment, and returns how many.
     */
    public function prune(CarbonInterface $before): int
    {
        return DB::table('page_cache_purges')->where('purged_at', '<', self::at($before))->delete();
    }

    /**
     * A moment as PostgreSQL takes it, in UTC, to the microsecond: a bound
     * Carbon would lose them.
     */
    private static function at(CarbonInterface $moment): string
    {
        return $moment->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
