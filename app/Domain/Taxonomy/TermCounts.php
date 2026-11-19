<?php

/**
 * Reads a term's counts per content type, which TermProjection keeps beside
 * the totals on terms (I-410). terms.published_count stays the total across
 * types, so nothing that reads it changes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use Illuminate\Support\Facades\DB;

final class TermCounts
{
    /**
     * The term's published and working counts for each type that uses it,
     * leaving out types where both are zero.
     *
     * @return array<string, array{published: int, working: int}>
     */
    public function forTerm(string $termId): array
    {
        $counts = [];

        foreach (DB::table('term_counts')->where('term_id', $termId)->whereRaw('(published > 0 OR working > 0)')->orderBy('type')->get() as $row) {
            $counts[(string) $row->type] = ['published' => (int) $row->published, 'working' => (int) $row->working];
        }

        return $counts;
    }

    /**
     * How many published items of these types the term is on, in one
     * statement; 0 for no types.
     */
    public function published(string $termId, string ...$types): int
    {
        if ($types === []) {
            return 0;
        }

        return (int) DB::table('term_counts')->where('term_id', $termId)->whereIn('type', $types)->sum('published');
    }

    /**
     * Applies one term's change for one type, by upsert. Call in a fixed term
     * order, as TermProjection does, so two Operations cannot deadlock.
     */
    public static function apply(string $termId, string $type, int $published, int $working): void
    {
        // PostgreSQL checks the CHECK on the proposed insert row before it
        // finds the conflict, so a negative delta can't go through the
        // upsert. It needs no insert either: a removal means the row exists.
        if ($published < 0 || $working < 0) {
            DB::table('term_counts')->where(['term_id' => $termId, 'type' => $type])->update([
                'published' => DB::raw('published + '.$published),
                'working' => DB::raw('working + '.$working),
            ]);

            return;
        }

        DB::statement(
            'INSERT INTO term_counts (term_id, type, published, working) VALUES (?, ?, ?, ?)
             ON CONFLICT (term_id, type) DO UPDATE SET published = term_counts.published + EXCLUDED.published, working = term_counts.working + EXCLUDED.working',
            [$termId, $type, $published, $working],
        );
    }
}
