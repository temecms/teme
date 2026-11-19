<?php

/**
 * A nesting type's items as a tree, for the Pages tree: every item not in
 * the trash, each under its parent, siblings in their order, with its live
 * address when it has one. Read only; moving items is not built yet.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Visibility;
use Illuminate\Support\Facades\DB;

final class TreeRows
{
    /** The most items the tree draws: past it, the list is the place to look. */
    public const int MOST = 500;

    /**
     * The tree's top items, each with its children nested under `children`,
     * and whether items were left out past MOST.
     *
     * @since next
     *
     * @return array{nodes: list<array{id: string, title: string, status: string, updatedAt: string, address: string|null, children: list<mixed>}>, cut: bool}
     */
    public static function of(string $type): array
    {
        $rows = DB::table('contents')
            ->leftJoin('paths', function ($join): void {
                $join->on('paths.target_id', '=', 'contents.id')
                    ->where('paths.target_type', 'content')
                    ->where('paths.is_current', true);
            })
            ->where('contents.type', $type)
            ->whereNull('contents.deleted_at')
            ->orderBy('contents.position')
            ->orderBy('contents.id')
            ->limit(self::MOST + 1)
            ->get(['contents.id', 'contents.title', 'contents.status', 'contents.visibility', 'contents.updated_at', 'contents.parent_id', 'paths.path']);
        $cut = $rows->count() > self::MOST;
        $rows = $rows->take(self::MOST);
        $ids = $rows->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $children = [];

        foreach ($rows as $row) {
            $parent = $row->parent_id !== null && in_array((string) $row->parent_id, $ids, true) ? (string) $row->parent_id : '';
            $children[$parent][] = $row;
        }

        $build = function (string $parent) use (&$build, $children, $type): array {
            return array_map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'status' => (string) $row->status,
                'updatedAt' => (string) $row->updated_at,
                'address' => $row->path !== null && $row->status === 'published' && $row->visibility !== Visibility::Private->value
                    ? Addresses::toUrl((string) $row->path, $type)
                    : null,
                'children' => $build((string) $row->id),
            ], $children[$parent] ?? []);
        };

        return ['nodes' => $build(''), 'cut' => $cut];
    }
}
