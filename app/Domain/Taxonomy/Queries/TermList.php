<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Support\Queries\ListPages;
use Illuminate\Support\Facades\DB;

/**
 * The rows of a taxonomy's screen, such as Categories and Tags: a nesting
 * taxonomy's terms as whole branches, a child under its parent, and a flat
 * taxonomy's, or any search's, by name, 50 at a time, by number up to the
 * cap and by keyset past it (I-3421). Each row carries what the screen's
 * form, confirmation and count links need (I-225).
 *
 * @phpstan-type Pages array{number: int|null, last: int, more: bool, from: int|null, to: int|null, total: int|null, newer: string|null, older: string|null}
 * @phpstan-type TermRow array{id: string, name: string, slug: string, description: string, path: string, depth: int, parentId: string|null, parentName: string|null, children: int, counts: array<string, int>, address: string|null}
 */
final class TermList
{
    private const int PAGE = 50;

    public function __construct(
        private readonly TermTree $tree,
        private readonly TermScreen $screen,
    ) {}

    /**
     * One page of terms by name, by number or either side of a cursor,
     * optionally narrowed to names starting with $search: a flat taxonomy's
     * screen, and any search, each term with its path where terms nest.
     * "paging" is its place among the pages, the count capped (ListPages),
     * with "newer" the cursor for the page before it and "older" for the
     * page after it, ContentList's names.
     *
     * @return array{rows: list<TermRow>, paging: Pages}
     */
    public function page(string $taxonomy, ?string $after, ?string $search, ?string $before = null, int $page = 1): array
    {
        $backwards = $before !== null && $before !== '';
        $cursor = Cursor::decode($backwards ? $before : $after, 2);
        $direction = $backwards ? 'desc' : 'asc';

        $query = DB::table('terms')
            ->where('taxonomy', $taxonomy)
            ->when($search !== null && $search !== '', fn ($query) => $query->whereRaw('lower(name) LIKE ?', [addcslashes(mb_strtolower((string) $search), '%_\\').'%']));
        // Counted up to the cap on every page, so every page costs the same.
        $cappedCount = ListPages::count($query, self::PAGE);
        $pageNumber = $cursor === null ? ListPages::requested($page) : null;

        $rows = $query
            ->when($cursor !== null, fn ($query) => $query->whereRaw($backwards ? '(name, id) < (?, ?)' : '(name, id) > (?, ?)', $cursor))
            ->when($cursor === null && $pageNumber > 1, fn ($query) => $query->offset(($pageNumber - 1) * self::PAGE))
            ->orderBy('name', $direction)
            ->orderBy('id', $direction)
            ->limit(self::PAGE + 1)
            ->get(['id', 'name']);

        $hasMore = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        if ($backwards) {
            $rows = $rows->reverse()->values();
        }

        $paths = $this->tree->nests($taxonomy) ? $this->tree->paths($rows->pluck('id')->all()) : [];
        $first = $rows->first();
        $last = $rows->last();

        return [
            'rows' => $this->rows($taxonomy, $rows->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'path' => $paths[$row->id] ?? (string) $row->name,
                'depth' => 0,
            ])->values()->all()),
            'paging' => ListPages::describe(
                $pageNumber,
                self::PAGE,
                $rows->count(),
                null,
                $cappedCount,
                $first !== null && ($backwards ? $hasMore : $cursor !== null) ? Cursor::encode([$first->name, $first->id]) : null,
                $last !== null && ($backwards || $hasMore) ? Cursor::encode([$last->name, $last->id]) : null,
            ),
        ];
    }

    /**
     * One page of a nesting taxonomy's terms as whole branches: top-level
     * terms by name, each followed by everything under it, a child under its
     * parent and a step deeper. A page holds up to 50 rows and never starts
     * or ends inside a branch; a branch longer than that has a page of its
     * own. The pages are numbered exactly, with no cap, since the branches
     * are already in memory (I-3421). A cursor wins over a number: "newer"
     * and "older" name the first and last top-level term shown, and one
     * that has gone since opens the first page.
     *
     * @param  list<array{id: string, name: string, parentId: string|null}>  $tree  every term of the taxonomy by name, as TermPicker::tree() gives them
     *
     * @return array{rows: list<TermRow>, paging: Pages}
     */
    public function branches(string $taxonomy, array $tree, ?string $after, ?string $before = null, int $page = 1): array
    {
        // Later (I-1491): a branch's children load when it is opened, for a tree too large to read whole.
        $branches = self::branchesOf($tree);

        if ($branches === []) {
            return ['rows' => [], 'paging' => ListPages::describe(1, self::PAGE, 0, 0, 0, null, null)];
        }

        $at = function (?string $cursor) use ($branches): ?int {
            $id = Cursor::decode($cursor, 1)[0] ?? null;

            foreach ($branches as $index => $branch) {
                if ($branch[0]['id'] === $id) {
                    return $index;
                }
            }

            return null;
        };

        $sizes = array_map(count(...), $branches);
        $windows = self::windows($sizes);
        $backwards = $before !== null && $before !== '';
        $cursor = $backwards ? $at($before) : $at($after);

        if ($cursor === null && ($backwards || $after === null || $after === '') && isset($windows[$page - 1])) {
            [$from, $to] = $windows[$page - 1];
        } else {
            [$from, $to] = self::window($sizes, $backwards ? null : $at($after), $backwards ? $at($before) : null);
        }

        $number = array_search([$from, $to], $windows, true);
        $rows = array_merge(...array_slice($branches, $from, $to - $from + 1));
        $total = array_sum($sizes);

        return [
            'rows' => $this->rows($taxonomy, $rows),
            'paging' => [
                'number' => $number === false ? null : $number + 1,
                'last' => count($windows),
                'more' => false,
                'from' => $number === false ? null : array_sum(array_slice($sizes, 0, $from)) + 1,
                'to' => $number === false ? null : array_sum(array_slice($sizes, 0, $to + 1)),
                'total' => $total,
                'newer' => $from > 0 ? Cursor::encode([$branches[$from][0]['id']]) : null,
                'older' => $to < count($branches) - 1 ? Cursor::encode([$branches[$to][0]['id']]) : null,
            ],
        ];
    }

    /**
     * Every page of branches in order, as paging forward from the first cuts
     * them, each its first and last branch.
     *
     * @param  list<int>  $sizes  each branch's rows, in order
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function windows(array $sizes): array
    {
        $windows = [];
        $start = 0;

        while (isset($sizes[$start])) {
            $windows[] = self::forward($sizes, $start);
            $start = $windows[array_key_last($windows)][1] + 1;
        }

        return $windows;
    }

    /**
     * The tree as branches in order: each top-level term by name, then
     * everything under it, depth first, siblings by name, each with its
     * depth and path. A term whose parent isn't in the tree counts as top
     * level. Terms caught in a parent cycle reach no top level and are left
     * out, since the walk starts only from the top.
     *
     * @param  list<array{id: string, name: string, parentId: string|null}>  $tree
     *
     * @return list<list<array{id: string, name: string, path: string, depth: int}>>
     */
    private static function branchesOf(array $tree): array
    {
        $known = [];

        foreach ($tree as $term) {
            $known[$term['id']] = true;
        }

        // '' holds the top level; a ULID is never empty.
        $children = [];

        foreach ($tree as $term) {
            $parent = $term['parentId'] !== null && isset($known[$term['parentId']]) ? $term['parentId'] : '';
            $children[$parent][] = $term;
        }

        $branches = [];

        foreach ($children[''] ?? [] as $root) {
            $rows = [];
            self::walk($children, $root, 0, $root['name'], $rows);
            $branches[] = $rows;
        }

        return $branches;
    }

    /**
     * Adds a term and everything under it to $rows, depth first.
     *
     * @param  array<array-key, list<array{id: string, name: string, parentId: string|null}>>  $children
     * @param  array{id: string, name: string, parentId: string|null}  $term
     * @param  list<array{id: string, name: string, path: string, depth: int}>  $rows
     */
    private static function walk(array $children, array $term, int $depth, string $path, array &$rows): void
    {
        $rows[] = ['id' => $term['id'], 'name' => $term['name'], 'path' => $path, 'depth' => $depth];

        foreach ($children[$term['id']] ?? [] as $child) {
            self::walk($children, $child, $depth + 1, $path.' › '.$child['name'], $rows);
        }
    }

    /**
     * The first and last branch of the page to show, by position: forward
     * from the branch after $after; or the page that ends just before
     * $before, cut as paging forward from the start cuts it, so Previous
     * returns to the page Next left; else the first page.
     *
     * @param  list<int>  $sizes  each branch's rows, in order
     *
     * @return array{0: int, 1: int}
     */
    private static function window(array $sizes, ?int $after, ?int $before): array
    {
        if ($before !== null && $before > 0) {
            $start = 0;

            while ($start < $before) {
                [$from, $to] = self::forward($sizes, $start);

                if ($to === $before - 1) {
                    return [$from, $to];
                }

                if ($to >= $before) {
                    break;
                }

                $start = $to + 1;
            }

            // The branches changed since that page was cut: as many whole
            // ones as fit, ending just before.
            $from = $before - 1;
            $rows = $sizes[$from];

            while ($from > 0 && $rows + $sizes[$from - 1] <= self::PAGE) {
                $from--;
                $rows += $sizes[$from];
            }

            return [$from, $before - 1];
        }

        return self::forward($sizes, $after !== null && isset($sizes[$after + 1]) ? $after + 1 : 0);
    }

    /**
     * The page that starts at branch $start: whole branches while they fit
     * in 50 rows, and always the first.
     *
     * @param  list<int>  $sizes
     *
     * @return array{0: int, 1: int}
     */
    private static function forward(array $sizes, int $start): array
    {
        $end = $start;
        $rows = $sizes[$start];

        while (isset($sizes[$end + 1]) && $rows + $sizes[$end + 1] <= self::PAGE) {
            $end++;
            $rows += $sizes[$end];
        }

        return [$start, $end];
    }

    /**
     * Each row as the screen draws it: its slug and description for the edit
     * form; its parent's name and how many terms sit directly under it for
     * the delete confirmation; and, for each listed type that uses the
     * taxonomy, how many of its items carry the term, leaving out the trash,
     * for the count links (term_counts' working counts, I-410). Two
     * statements, whatever the rows.
     *
     * @param  array<int, array{id: string, name: string, path: string, depth: int}>  $page
     *
     * @return list<TermRow>
     */
    private function rows(string $taxonomy, array $page): array
    {
        if ($page === []) {
            return [];
        }

        $ids = array_column($page, 'id');
        $types = array_column($this->screen->countedTypes($taxonomy), 'key');

        $details = DB::table('terms AS t')
            ->leftJoin('terms AS p', 'p.id', '=', 't.parent_id')
            ->whereIn('t.id', $ids)
            ->select(['t.id', 't.slug', 't.description', 't.parent_id', 'p.name AS parent_name'])
            ->selectRaw('(SELECT count(*) FROM terms AS c WHERE c.taxonomy = t.taxonomy AND c.parent_id = t.id) AS children')
            ->get()
            ->keyBy('id');

        $counts = [];

        foreach (DB::table('term_counts')->whereIn('term_id', $ids)->whereIn('type', $types)->get(['term_id', 'type', 'working']) as $count) {
            $counts[(string) $count->term_id][(string) $count->type] = (int) $count->working;
        }

        $addresses = $this->addresses($taxonomy, $ids);
        $rows = [];

        foreach ($page as $row) {
            $detail = $details->get($row['id']);

            $rows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'slug' => (string) ($detail->slug ?? ''),
                'description' => (string) ($detail->description ?? ''),
                'path' => $row['path'],
                'depth' => $row['depth'],
                'parentId' => isset($detail->parent_id) ? (string) $detail->parent_id : null,
                'parentName' => isset($detail->parent_name) ? (string) $detail->parent_name : null,
                'children' => (int) ($detail->children ?? 0),
                'counts' => array_combine($types, array_map(fn (string $type): int => $counts[$row['id']][$type] ?? 0, $types)),
                'address' => $addresses[$row['id']] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Each term's public address as Addresses::forTerm() gives it, with its
     * slashes: the taxonomy's base, the slugs of its ancestors, then its own,
     * read in one statement for the whole page. None for a taxonomy that
     * has no public pages.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    private function addresses(string $taxonomy, array $ids): array
    {
        $settings = AddressSettings::current();

        if (! $settings->hasBase($taxonomy)) {
            return [];
        }

        $base = $settings->base($taxonomy);
        $addresses = [];

        $marks = implode(', ', array_fill(0, count($ids), '?'));

        foreach (DB::select(<<<SQL
            WITH RECURSIVE chain AS (
                SELECT id AS term_id, id, parent_id, slug, 0 AS depth FROM terms WHERE id IN ({$marks})
                UNION ALL
                SELECT chain.term_id, t.id, t.parent_id, t.slug, chain.depth + 1 FROM terms t JOIN chain ON t.id = chain.parent_id
            )
            SELECT term_id, string_agg(slug, '/' ORDER BY depth DESC) AS path FROM chain GROUP BY term_id
            SQL, $ids) as $row) {
            $parts = array_filter([$base, (string) $row->path], fn (string $part): bool => $part !== '');
            $addresses[(string) $row->term_id] = '/'.mb_strtolower(implode('/', $parts)).'/';
        }

        return $addresses;
    }
}
