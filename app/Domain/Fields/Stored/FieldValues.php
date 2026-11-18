<?php

/**
 * What items hold for a stored field, read-only: how many hold a value for
 * each key, in the working revision, the live copy or an autosave, trashed
 * items included (docs/studies/content-and-fields.md §5.3).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Stored;

use Illuminate\Support\Facades\DB;

final class FieldValues
{
    /**
     * How many of the type's items hold a value for each key: in the working
     * revision, the live copy or the autosave, trashed items included. Keys
     * nobody holds are left out.
     *
     * @param  list<string>  $keys
     *
     * @return array<string, int>
     */
    public static function counts(string $type, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return DB::table('contents as c')
            ->join('content_revisions as w', 'w.id', '=', 'c.working_revision_id')
            ->leftJoin('content_autosaves as a', 'a.content_id', '=', 'c.id')
            ->crossJoin(DB::raw('unnest(?::text[]) AS k(key)'))
            ->addBinding('{'.implode(',', $keys).'}', 'join')
            ->where('c.type', $type)
            ->whereRaw('(jsonb_exists(w.fields, k.key) OR jsonb_exists(c.fields, k.key) OR jsonb_exists(COALESCE(a.fields, \'{}\'::jsonb), k.key))')
            ->groupBy('k.key')
            // The alias names the column pluck() reads (it reads the text after " as ").
            ->pluck(DB::raw('count(DISTINCT c.id) AS holding'), 'k.key')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * How many of the type's items hold a value for the key, split into the
     * published and live, and the rest: drafts, scheduled, in review, trashed.
     *
     * @return array{published: int, unpublished: int}
     */
    public static function split(string $type, string $key): array
    {
        $rows = DB::table('contents as c')
            ->join('content_revisions as w', 'w.id', '=', 'c.working_revision_id')
            ->leftJoin('content_autosaves as a', 'a.content_id', '=', 'c.id')
            ->where('c.type', $type)
            ->whereRaw('(jsonb_exists(w.fields, ?) OR jsonb_exists(c.fields, ?) OR jsonb_exists(COALESCE(a.fields, \'{}\'::jsonb), ?))', [$key, $key, $key])
            ->groupByRaw("(c.status = 'published' AND c.deleted_at IS NULL)")
            ->selectRaw("(c.status = 'published' AND c.deleted_at IS NULL) AS live, count(DISTINCT c.id) AS holding")
            ->get();

        $split = ['published' => 0, 'unpublished' => 0];

        foreach ($rows as $row) {
            $split[$row->live ? 'published' : 'unpublished'] = (int) $row->holding;
        }

        return $split;
    }
}
