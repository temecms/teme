<?php

/**
 * Reads the tree of a type whose items nest, such as pages (I-3363), stored
 * as an adjacency list on contents: every walk is one bounded recursive
 * query, so a loop written outside the Operations can never make one hang.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

use Illuminate\Support\Facades\DB;

final class ContentTree
{
    /** The deepest a tree goes: an item and seven ancestors. */
    public const int MOST_LEVELS = 8;

    /**
     * The {parent} value for an item under $parentId: the slugs of that item
     * and its ancestors that have an address, top first, joined by slashes;
     * '' at the top or when none has one (I-3363).
     */
    public function parentPath(?string $parentId): string
    {
        if ($parentId === null) {
            return '';
        }

        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE up AS (
                SELECT c.id, c.parent_id, c.slug, c.status, c.visibility, c.deleted_at, 0 AS depth
                FROM contents c WHERE c.id = ?
                UNION ALL
                SELECT p.id, p.parent_id, p.slug, p.status, p.visibility, p.deleted_at, up.depth + 1
                FROM up CROSS JOIN LATERAL (
                    SELECT id, parent_id, slug, status, visibility, deleted_at FROM contents WHERE contents.id = up.parent_id LIMIT 1
                ) p
                WHERE up.depth < ?
            )
            SELECT slug FROM up
            WHERE deleted_at IS NULL AND status = 'published' AND visibility <> 'private'
            ORDER BY depth DESC
            SQL, [$parentId, self::MOST_LEVELS - 2]);

        return implode('/', array_map(fn (object $row): string => (string) $row->slug, $rows));
    }

    /**
     * The item and its ancestors, every state, bottom first. Bounded one
     * level past the deepest a tree may go, so a chain that is already too
     * deep is seen as such.
     *
     * @return list<string>
     */
    public function ancestorIds(string $id): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE up AS (
                SELECT c.id, c.parent_id, 0 AS depth FROM contents c WHERE c.id = ?
                UNION ALL
                SELECT p.id, p.parent_id, up.depth + 1
                FROM up CROSS JOIN LATERAL (
                    SELECT id, parent_id FROM contents WHERE contents.id = up.parent_id LIMIT 1
                ) p
                WHERE up.depth < ?
            )
            SELECT id FROM up ORDER BY depth
            SQL, [$id, self::MOST_LEVELS]);

        return array_map(fn (object $row): string => (string) $row->id, $rows);
    }

    /**
     * The level the item is at: its ancestors plus one; 0 for an item that doesn't exist.
     */
    public function levels(string $id): int
    {
        return count($this->ancestorIds($id));
    }

    /**
     * How many levels the item and everything under it take: one plus its
     * deepest descendant's depth.
     */
    public function height(string $id): int
    {
        return 1 + (int) DB::scalar(<<<'SQL'
            WITH RECURSIVE down AS (
                SELECT c.id, 1 AS depth FROM contents c WHERE c.parent_id = ?
                UNION ALL
                SELECT c.id, down.depth + 1 FROM down JOIN contents c ON c.parent_id = down.id
                WHERE down.depth < ?
            )
            SELECT COALESCE(max(depth), 0) FROM down
            SQL, [$id, self::MOST_LEVELS - 1]);
    }

    /**
     * Whether any item, in the trash or not, sits under this one.
     */
    public function hasChildren(string $id): bool
    {
        return (bool) DB::scalar('SELECT EXISTS (SELECT 1 FROM contents WHERE parent_id = ?)', [$id]);
    }

    /**
     * Everything under the item, every state, depth first then by ID, each
     * with whether it has an address of its own (published, not private,
     * not in the trash).
     *
     * @return list<object{id: string, parent_id: string, slug: string, depth: int, shown: bool}>
     */
    public function descendants(string $id): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE down AS (
                SELECT c.id, c.parent_id, c.slug, 1 AS depth,
                       (c.deleted_at IS NULL AND c.status = 'published' AND c.visibility <> 'private') AS shown
                FROM contents c WHERE c.parent_id = ?
                UNION ALL
                SELECT c.id, c.parent_id, c.slug, down.depth + 1,
                       (c.deleted_at IS NULL AND c.status = 'published' AND c.visibility <> 'private')
                FROM down JOIN contents c ON c.parent_id = down.id
                WHERE down.depth < ?
            )
            SELECT id, parent_id, slug, depth, shown FROM down ORDER BY depth, id
            SQL, [$id, self::MOST_LEVELS - 1]);

        /** @var list<object{id: string, parent_id: string, slug: string, depth: int, shown: bool}> */
        return array_map(fn (object $row): object => (object) [
            'id' => (string) $row->id,
            'parent_id' => (string) $row->parent_id,
            'slug' => (string) $row->slug,
            'depth' => (int) $row->depth,
            'shown' => (bool) $row->shown,
        ], $rows);
    }
}
