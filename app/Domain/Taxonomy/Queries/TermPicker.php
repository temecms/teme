<?php

/**
 * Finds categories or tags by the start of their name, for the pickers in
 * quick edit and, later, the editor. Never returns more than 20, and names one by
 * its ID for a list's filter.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Queries;

use Illuminate\Support\Facades\DB;

final class TermPicker
{
    private const int LIMIT = 20;

    public function __construct(private readonly TermTree $tree) {}

    /**
     * Up to 20 terms whose names start with $prefix, case-insensitively, in
     * name order, each with its path. With an empty $prefix, the 20 most used
     * first (most published items, then name), so a picker has something to
     * list before anything is typed.
     *
     * @return list<array{id: string, name: string, path: string}>
     */
    public function search(string $taxonomy, string $prefix): array
    {
        $rows = DB::table('terms')
            ->where('taxonomy', $taxonomy)
            ->whereRaw('lower(name) LIKE ?', [addcslashes(mb_strtolower($prefix), '%_\\').'%'])
            ->when($prefix === '', fn ($query) => $query->orderByDesc('published_count'))
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name']);

        $paths = $this->tree->nests($taxonomy) ? $this->tree->paths($rows->pluck('id')->all()) : [];

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'path' => $paths[$row->id] ?? (string) $row->name,
        ])->values()->all();
    }

    /**
     * Every term of a hierarchical taxonomy, in name order, each with its
     * parent's ID or null: what the editor's tree picker draws.
     *
     * @return list<array{id: string, name: string, parentId: string|null}>
     */
    public function tree(string $taxonomy): array
    {
        return DB::table('terms')
            ->where('taxonomy', $taxonomy)
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get(['id', 'name', 'parent_id'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'parentId' => $row->parent_id === null ? null : (string) $row->parent_id,
            ])
            ->values()
            ->all();
    }

    /**
     * The name of one term of this taxonomy, or null when there is none:
     * what a list shows for a filter chosen by ID, such as a count on a
     * term's screen opens.
     */
    public function name(string $taxonomy, string $id): ?string
    {
        $name = DB::table('terms')->where('taxonomy', $taxonomy)->where('id', $id)->value('name');

        return $name === null ? null : (string) $name;
    }
}
