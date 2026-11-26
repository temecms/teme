<?php

/**
 * A page's family as readers see it (I-3363): the items above it and the
 * items under it that are live, each by its published title at its
 * address, one statement each. Each records the tags of what it shows. The
 * time is the application's, as Live binds it, never the database's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ViewModels\ItemLink;
use Illuminate\Support\Facades\DB;

final readonly class PageFamily
{
    /** The most children a page's view data lists. */
    public const int CHILDREN = 100;

    /**
     * The live items above the item, the top first; a hidden one is left
     * out, those above it kept.
     *
     * @return list<ItemLink>
     */
    public function ancestors(string $id): array
    {
        return $this->lineage($id)[0];
    }

    /**
     * The live items above the item, the top first, and its own parent among
     * them, or null while that parent is hidden, in one statement.
     *
     * @return array{0: list<ItemLink>, 1: ItemLink|null}
     */
    public function lineage(string $id): array
    {
        [$shown, $hidden] = self::shown();
        $rows = DB::select(<<<SQL
            WITH RECURSIVE up AS (
                SELECT c.id, c.parent_id, 0 AS depth FROM contents c WHERE c.id = ?
                UNION ALL
                SELECT p.id, p.parent_id, up.depth + 1
                FROM up CROSS JOIN LATERAL (SELECT id, parent_id FROM contents WHERE contents.id = up.parent_id LIMIT 1) p
                WHERE up.depth < ?
            )
            SELECT c.id, c.type, r.title, paths.path, up.depth
            FROM up
            JOIN contents c ON c.id = up.id
            JOIN content_revisions r ON r.id = c.published_revision_id
            JOIN paths ON paths.target_type = 'content' AND paths.target_id = c.id::text AND paths.is_current
            WHERE up.depth > 0 AND c.deleted_at IS NULL AND c.status = 'published'
              AND c.visibility <> 'private' AND c.published_at <= ?{$shown}
            ORDER BY up.depth DESC
            SQL, [$id, ContentTree::MOST_LEVELS - 1, now(), ...$hidden]);
        $links = $this->links($rows);
        $last = array_key_last($rows);

        return [$links, $last !== null && (int) $rows[$last]->depth === 1 ? $links[$last] : null];
    }

    /**
     * The live items under the item, in their order, at most 100; records
     * the list of its children too, so a child published or moved under it
     * sets its page aside.
     *
     * @return list<ItemLink>
     */
    public function children(string $id): array
    {
        app(RenderRecord::class)->tag(PageTags::childrenOf($id));

        [$shown, $hidden] = self::shown();
        $rows = DB::select(<<<SQL
            SELECT c.id, c.type, r.title, paths.path
            FROM contents c
            JOIN content_revisions r ON r.id = c.published_revision_id
            JOIN paths ON paths.target_type = 'content' AND paths.target_id = c.id::text AND paths.is_current
            WHERE c.parent_id = ? AND c.deleted_at IS NULL AND c.status = 'published'
              AND c.visibility <> 'private' AND c.published_at <= ?{$shown}
            ORDER BY c.position, c.id
            LIMIT ?
            SQL, [$id, now(), ...$hidden, self::CHILDREN]);

        return $this->links($rows);
    }

    /**
     * The live items that share the item's parent and type, itself among
     * them, in menu order, at most 100; for a top-level item, the top-level
     * items of its type in its language. Records the list that sets the
     * page aside when one is added, moved or taken off.
     *
     * @return list<ItemLink>
     */
    public function siblings(Content $content): array
    {
        app(RenderRecord::class)->tag($content->parent_id === null ? PageTags::listOf((string) $content->type) : PageTags::childrenOf((string) $content->parent_id));

        [$shown, $hidden] = self::shown();
        $rows = DB::select(<<<SQL
            SELECT c.id, c.type, r.title, paths.path
            FROM contents c
            JOIN content_revisions r ON r.id = c.published_revision_id
            JOIN paths ON paths.target_type = 'content' AND paths.target_id = c.id::text AND paths.is_current
            WHERE c.parent_id IS NOT DISTINCT FROM ? AND c.type = ? AND c.language IS NOT DISTINCT FROM ?
              AND c.deleted_at IS NULL AND c.status = 'published'
              AND c.visibility <> 'private' AND c.published_at <= ?{$shown}
            ORDER BY c.position, c.id
            LIMIT ?
            SQL, [$content->parent_id, $content->type, $content->language, now(), ...$hidden, self::CHILDREN]);

        return $this->links($rows);
    }

    /**
     * The live sibling just before the item in menu order, or the one just
     * after it ($before false); null at the ends. Found by its position, so
     * it is right however many siblings there are.
     */
    public function neighbour(Content $content, bool $before): ?ItemLink
    {
        [$shown, $hidden] = self::shown();
        [$compare, $order] = $before ? ['<', 'DESC'] : ['>', 'ASC'];
        $rows = DB::select(<<<SQL
            SELECT c.id, c.type, r.title, paths.path
            FROM contents c
            JOIN content_revisions r ON r.id = c.published_revision_id
            JOIN paths ON paths.target_type = 'content' AND paths.target_id = c.id::text AND paths.is_current
            WHERE c.parent_id IS NOT DISTINCT FROM ? AND c.type = ? AND c.language IS NOT DISTINCT FROM ?
              AND (c.position, c.id) {$compare} (?, ?)
              AND c.deleted_at IS NULL AND c.status = 'published'
              AND c.visibility <> 'private' AND c.published_at <= ?{$shown}
            ORDER BY c.position {$order}, c.id {$order}
            LIMIT 1
            SQL, [$content->parent_id, $content->type, $content->language, $content->position, (string) $content->id, now(), ...$hidden]);

        return $this->links($rows)[0] ?? null;
    }

    /**
     * The condition, after an AND, keeping to the languages readers may see
     * in (D7), with its bindings: none while no language is hidden. A page's
     * family shares its language, but an import can break that rule.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function shown(): array
    {
        $hidden = app(SiteLanguages::class)->hidden();

        return $hidden === [] ? ['', []] : [' AND (c.language IS NULL OR c.language NOT IN ('.implode(', ', array_fill(0, count($hidden), '?')).'))', $hidden];
    }

    /**
     * @param  list<object>  $rows
     *
     * @return list<ItemLink>
     */
    private function links(array $rows): array
    {
        app(RenderRecord::class)->tag(...array_map(fn (object $row): string => PageTags::content((string) $row->id), $rows));

        return array_map(fn (object $row): ItemLink => new ItemLink((string) $row->title, Addresses::toUrl((string) $row->path, (string) $row->type), (string) $row->type), $rows);
    }
}
