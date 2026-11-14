<?php

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * An imported site's page tree as WordPress had it (I-3363): its pages
 * top-down, each with its rank among its WordPress siblings, for placing
 * them under their parents; and the notes of those left where they were.
 */
final readonly class ImportedPageTree
{
    /** The deepest a WordPress branch is followed; deeper pages, and those in a loop, are never reached. */
    private const int DEEPEST = 64;

    public function __construct(private ContentTypeRegistry $types) {}

    /**
     * The keys of the types whose items have a parent.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_values(array_map(
            fn (ContentType $type): string => $type->key,
            array_filter($this->types->all(), fn (ContentType $type): bool => $type->hierarchical),
        ));
    }

    /**
     * Up to $limit of the site's imported pages after the cursor, parents
     * before their children: each with its map object ID, its item, its
     * WordPress parent's object ID (0 for none), its depth from a branch's
     * start, and its rank among its WordPress siblings in WordPress's order.
     * A page whose parent isn't among the site's pages starts a branch.
     *
     * @return list<array{object_id: string, teme_id: string, wp_parent: int, depth: int, rank: int}>
     */
    public function batch(string $sourceId, int $afterDepth, int $afterId, int $limit): array
    {
        $types = $this->types();

        if ($types === []) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE pages AS (
                SELECT m.object_id, m.teme_id, c.title,
                       COALESCE((m.facts->>'parent')::bigint, 0) AS wp_parent,
                       CASE WHEN jsonb_typeof(m.facts->'menu_order') = 'number' THEN (m.facts->>'menu_order')::numeric ELSE 0 END AS menu_order
                FROM import_map m JOIN contents c ON c.id = m.teme_id
                WHERE m.source_id = ? AND m.object_type = 'post' AND m.teme_type = 'content' AND m.status = 'imported'
                  AND c.type = ANY(?::text[])
            ),
            tree AS (
                SELECT p.*, 1 AS depth FROM pages p
                WHERE p.wp_parent = 0 OR NOT EXISTS (SELECT 1 FROM pages q WHERE q.object_id = p.wp_parent::text)
                UNION ALL
                SELECT p.*, tree.depth + 1 FROM pages p JOIN tree ON p.wp_parent::text = tree.object_id
                WHERE tree.depth < ?
            ),
            ranked AS (
                SELECT object_id, teme_id, wp_parent, depth,
                       row_number() OVER (PARTITION BY wp_parent ORDER BY menu_order, lower(title), object_id::bigint) - 1 AS rank
                FROM tree
            )
            SELECT object_id, teme_id, wp_parent, depth, rank FROM ranked
            WHERE (depth, object_id::bigint) > (?, ?)
            ORDER BY depth, object_id::bigint
            LIMIT ?
            SQL, [$sourceId, '{'.implode(',', $types).'}', self::DEEPEST, $afterDepth, $afterId, $limit]);

        return array_map(fn (object $row): array => [
            'object_id' => (string) $row->object_id,
            'teme_id' => (string) $row->teme_id,
            'wp_parent' => (int) $row->wp_parent,
            'depth' => (int) $row->depth,
            'rank' => (int) $row->rank,
        ], $rows);
    }

    /**
     * How many of the site's imported pages had a parent on WordPress.
     */
    public function withParents(string $sourceId): int
    {
        return $this->count($sourceId, true);
    }

    /**
     * How many of the site's imported pages there are, which a walk visits.
     */
    public function pages(string $sourceId): int
    {
        return $this->count($sourceId, false);
    }

    private function count(string $sourceId, bool $withParent): int
    {
        $types = $this->types();

        if ($types === []) {
            return 0;
        }

        return DB::table('import_map')
            ->join('contents', 'contents.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $sourceId)->where('import_map.object_type', 'post')->where('import_map.teme_type', 'content')->where('import_map.status', 'imported')
            ->whereIn('contents.type', $types)
            ->when($withParent, fn ($query) => $query->whereRaw("COALESCE((import_map.facts->>'parent')::bigint, 0) > 0"))
            ->count();
    }

    /**
     * Whether any of the site's pages has been placed under its parent before.
     */
    public function placedBefore(string $sourceId): bool
    {
        return DB::table('import_map')->where('source_id', $sourceId)->where('object_type', 'post')->whereRaw("facts->'placed' IS NOT NULL")->exists();
    }

    /**
     * Up to $limit of the site's pages left where they were, after the
     * object ID $after, by object ID: each with its title and the note
     * saying why.
     *
     * @return list<array{object_id: string, title: string, note: string}>
     */
    public function notes(string $sourceId, ?string $after = null, int $limit = 50): array
    {
        return DB::table('import_map')
            ->join('contents', 'contents.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $sourceId)->where('import_map.object_type', 'post')
            ->whereRaw("import_map.facts->'parent_note' IS NOT NULL")
            ->when($after !== null, fn ($query) => $query->whereRaw('import_map.object_id::bigint > ?::bigint', [$after]))
            ->orderByRaw('import_map.object_id::bigint')
            ->limit($limit)
            ->get(['import_map.object_id', 'contents.title', DB::raw("import_map.facts->>'parent_note' AS note")])
            ->map(fn (object $row): array => ['object_id' => (string) $row->object_id, 'title' => (string) $row->title, 'note' => (string) $row->note])
            ->all();
    }
}
