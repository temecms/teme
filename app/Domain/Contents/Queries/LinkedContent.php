<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Whether each article a document links to can be reached: published, not
 * published, in the trash, or gone. The editor's checks warn about links to
 * any but the first (I-427). It reads only.
 */
final class LinkedContent
{
    /**
     * Each asked-for ID's title and state, keyed by ID: `published`,
     * `unpublished` (a draft, scheduled or in review), `trashed`, or
     * `missing` with a null title when no row has the ID.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{title: string|null, state: 'published'|'unpublished'|'trashed'|'missing'}>
     */
    public static function states(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // The query builder, so trashed rows come back too.
        $rows = DB::table('contents')
            ->whereIn('id', $ids)
            ->get(['id', 'title', 'status', 'deleted_at'])
            ->keyBy('id');

        $states = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            $states[$id] = match (true) {
                $row === null => ['title' => null, 'state' => 'missing'],
                $row->deleted_at !== null => ['title' => (string) $row->title, 'state' => 'trashed'],
                $row->status === 'published' => ['title' => (string) $row->title, 'state' => 'published'],
                default => ['title' => (string) $row->title, 'state' => 'unpublished'],
            };
        }

        return $states;
    }
}
