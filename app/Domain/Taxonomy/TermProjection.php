<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Visibility;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Keeps content_terms equal to what an item's revisions say (I-144): the
 * working revision's terms while the item is live, and the published
 * revision's terms while it is live, published and not private. Adjusts
 * terms.published_count and terms.working_count by exactly the rows it adds
 * or removes, so no count is ever recomputed on publish.
 */
final class TermProjection
{
    /**
     * Brings one item's rows into line with its revisions. Call last in every
     * content Operation's perform(), after ContentCounts::apply().
     */
    public static function sync(Content $content): void
    {
        $desired = self::desired($content);
        $current = self::current($content->id);

        /** @var array<string, int> $publishedDelta term ID => change */
        $publishedDelta = [];

        /** @var array<string, int> $workingDelta term ID => change */
        $workingDelta = [];

        foreach ($current as $key => $position) {
            if (! array_key_exists($key, $desired)) {
                [$scope, $termId] = explode('|', $key, 2);
                DB::table('content_terms')->where(['content_id' => $content->id, 'scope' => $scope, 'term_id' => $termId])->delete();

                if ($scope === 'published') {
                    $publishedDelta[$termId] = ($publishedDelta[$termId] ?? 0) - 1;
                } else {
                    $workingDelta[$termId] = ($workingDelta[$termId] ?? 0) - 1;
                }
            }
        }

        foreach ($desired as $key => $position) {
            if (($current[$key] ?? null) === $position) {
                continue;
            }

            [$scope, $termId] = explode('|', $key, 2);

            DB::table('content_terms')->upsert(
                [['content_id' => $content->id, 'term_id' => $termId, 'scope' => $scope, 'position' => $position]],
                ['content_id', 'scope', 'term_id'],
                ['position'],
            );

            if (! array_key_exists($key, $current)) {
                if ($scope === 'published') {
                    $publishedDelta[$termId] = ($publishedDelta[$termId] ?? 0) + 1;
                } else {
                    $workingDelta[$termId] = ($workingDelta[$termId] ?? 0) + 1;
                }
            }
        }

        // A fixed order, so two Operations counting the same terms lock them
        // in the same order and cannot deadlock.
        ksort($publishedDelta);
        ksort($workingDelta);

        foreach ($publishedDelta as $termId => $delta) {
            if ($delta !== 0) {
                DB::table('terms')->where('id', $termId)->increment('published_count', $delta);
            }
        }

        foreach ($workingDelta as $termId => $delta) {
            if ($delta !== 0) {
                DB::table('terms')->where('id', $termId)->increment('working_count', $delta);
            }
        }

        // The same deltas per type, in the same fixed term order (I-410).
        $termIds = array_unique([...array_keys($publishedDelta), ...array_keys($workingDelta)]);
        sort($termIds, SORT_STRING);

        foreach ($termIds as $termId) {
            $published = $publishedDelta[$termId] ?? 0;
            $working = $workingDelta[$termId] ?? 0;

            if ($published !== 0 || $working !== 0) {
                TermCounts::apply((string) $termId, $content->type, $published, $working);
            }
        }
    }

    /**
     * @return array<string, int> "scope|termId" => position
     */
    private static function desired(Content $content): array
    {
        if ($content->trashed()) {
            return [];
        }

        $scopes = ['working' => $content->working_revision_id];

        if ($content->status === 'published' && $content->published_revision_id !== null && $content->visibility !== Visibility::Private) {
            $scopes['published'] = $content->published_revision_id;
        }

        $lists = ContentRevision::query()->whereIn('id', array_values(array_filter($scopes)))->pluck('terms', 'id');
        $wanted = [];

        foreach ($scopes as $scope => $revisionId) {
            $raw = $lists[$revisionId] ?? '[]';
            $ids = array_values(array_unique(is_string($raw) ? (array) json_decode($raw, true) : (array) $raw));

            foreach ($ids as $position => $termId) {
                $wanted["{$scope}|{$termId}"] = $position;
            }
        }

        $taxonomies = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->forType($content->type));

        if ($wanted === [] || $taxonomies === []) {
            return [];
        }

        // Terms deleted since the revision was written drop out here, and so
        // do terms of a taxonomy the type no longer uses: a page's old tags
        // stay in its revisions but are never projected again (I-417).
        $existing = DB::table('terms')
            ->whereIn('id', array_unique(array_map(fn (string $key): string => explode('|', $key, 2)[1], array_keys($wanted))))
            ->whereIn('taxonomy', $taxonomies)
            ->pluck('id')
            ->flip();

        return array_filter($wanted, fn (int $position, string $key): bool => $existing->has(explode('|', $key, 2)[1]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, int> "scope|termId" => position
     */
    private static function current(string $contentId): array
    {
        $rows = [];

        foreach (DB::table('content_terms')->where('content_id', $contentId)->get(['scope', 'term_id', 'position']) as $row) {
            $rows["{$row->scope}|{$row->term_id}"] = (int) $row->position;
        }

        return $rows;
    }
}
