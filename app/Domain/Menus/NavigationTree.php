<?php

declare(strict_types=1);

namespace App\Domain\Menus;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Menus\Models\Navigation;
use Illuminate\Support\Facades\DB;

/**
 * One menu as the site draws it: each item's label and site-relative URL,
 * made now from the path table (rule 9), whether it is the page being
 * shown, whether it is a heading, which has no URL and opens the items
 * under it (I-2628), and its children. An item whose target has no
 * address, being unpublished, trashed or deleted, is left out with its
 * children, and so is a heading with nothing drawn under it. Four queries
 * at most: the items, their content, their terms and their paths.
 */
final class NavigationTree
{
    /**
     * The drawable tree. $currentPath is the page's request path, already
     * canonical, since PathResolver redirects every other form; '' when the
     * view isn't a page, so nothing is current.
     *
     * @return list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>
     */
    public static function for(Navigation $navigation, string $currentPath): array
    {
        app(RenderRecord::class)->tag(PageTags::menu((string) $navigation->handle));

        $items = DB::table('navigation_items')
            ->where('navigation_id', $navigation->id)
            ->orderBy('position')
            ->get(['id', 'parent_id', 'kind', 'target_id', 'url', 'label', 'opens_new_tab']);

        if ($items->isEmpty()) {
            return [];
        }

        $contentIds = $items->where('kind', 'content')->pluck('target_id')->unique()->values()->all();
        $termIds = $items->where('kind', 'term')->pluck('target_id')->unique()->values()->all();

        // What each item points at, drawn or left out: publishing it brings it back (D2).
        app(RenderRecord::class)->tag(
            ...array_map(fn (mixed $id): string => PageTags::content((string) $id), $contentIds),
            ...array_map(fn (mixed $id): string => PageTags::term((string) $id), $termIds),
        );

        $contents = self::liveContent($contentIds);
        $terms = self::termNames($termIds);
        $paths = self::paths(array_keys($contents), array_keys($terms));

        $addressed = [];

        foreach ($items as $item) {
            $drawn = self::drawn($item, $contents, $terms, $paths, rawurldecode($currentPath));

            if ($drawn !== null) {
                $addressed[(string) $item->id] = $drawn + ['parent' => $item->parent_id === null ? null : (string) $item->parent_id];
            }
        }

        return self::children($addressed, null);
    }

    /**
     * The published, live content among these IDs, with its type and its
     * published title: `contents.title` is the working title, which may be
     * an unpublished draft's.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{type: string, title: string}>
     */
    private static function liveContent(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $live = [];

        foreach (DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->whereIn('contents.id', $ids)
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->where('contents.published_at', '<=', now())
            // Private items are read in the admin, never here (I-1700).
            ->where('contents.visibility', '<>', 'private')
            ->get(['contents.id', 'contents.type', 'content_revisions.title']) as $row) {
            $live[(string) $row->id] = ['type' => (string) $row->type, 'title' => (string) $row->title];
        }

        return $live;
    }

    /**
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    private static function termNames(array $ids): array
    {
        return $ids === [] ? [] : DB::table('terms')->whereIn('id', $ids)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
    }

    /**
     * Each target's current path, keyed "content:{id}" or "term:{id}", in one query.
     *
     * @param  list<string>  $contentIds
     * @param  list<string>  $termIds
     *
     * @return array<string, string>
     */
    private static function paths(array $contentIds, array $termIds): array
    {
        if ($contentIds === [] && $termIds === []) {
            return [];
        }

        $paths = [];

        foreach (DB::table('paths')
            ->where('is_current', true)
            ->where(fn ($query) => $query
                ->where(fn ($content) => $content->where('target_type', 'content')->whereIn('target_id', $contentIds))
                ->orWhere(fn ($term) => $term->where('target_type', 'term')->whereIn('target_id', $termIds)))
            ->get(['target_type', 'target_id', 'path']) as $row) {
            // A target in a hidden language is left out, as one with no address is (D7).
            if (! app(SiteLanguages::class)->isHiddenPath((string) $row->path)) {
                $paths["{$row->target_type}:{$row->target_id}"] = (string) $row->path;
            }
        }

        return $paths;
    }

    /**
     * One item's label, URL, whether it is current and whether it is a
     * heading, or null when its target has no address. A heading has no
     * URL and is never current: it leads nowhere.
     *
     * @param  array<string, array{type: string, title: string}>  $contents
     * @param  array<string, string>  $terms
     * @param  array<string, string>  $paths
     *
     * @return array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool}|null
     */
    private static function drawn(object $item, array $contents, array $terms, array $paths, string $currentPath): ?array
    {
        if ((string) $item->kind === 'heading') {
            return ['label' => (string) $item->label, 'url' => null, 'current' => false, 'opensNewTab' => false, 'heading' => true];
        }

        $target = (string) $item->target_id;

        [$url, $title] = match ((string) $item->kind) {
            'home' => [Addresses::toUrl(''), __('Home')],
            'content' => isset($contents[$target], $paths["content:{$target}"])
                ? [Addresses::toUrl($paths["content:{$target}"], $contents[$target]['type']), $contents[$target]['title']]
                : [null, ''],
            'term' => isset($terms[$target], $paths["term:{$target}"])
                ? [Addresses::toUrl($paths["term:{$target}"]), $terms[$target]]
                : [null, ''],
            default => [(string) $item->url, (string) $item->url],
        };

        if ($url === null) {
            return null;
        }

        // An outside address is never the page being shown.
        $onThisSite = str_starts_with($url, '/') && ! str_starts_with($url, '//');

        return [
            'label' => $item->label !== null && $item->label !== '' ? (string) $item->label : $title,
            'url' => $url,
            'current' => $onThisSite && $currentPath !== '' && $url === $currentPath,
            'opensNewTab' => (bool) $item->opens_new_tab,
            'heading' => false,
        ];
    }

    /**
     * The drawable children of one parent, in order. A child whose parent
     * was left out never reaches here, and a heading with nothing drawn
     * under it is left out: it would open nothing.
     *
     * @param  array<string, array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, parent: string|null}>  $addressed
     *
     * @return list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>
     */
    private static function children(array $addressed, ?string $parent): array
    {
        $children = [];

        foreach ($addressed as $id => $item) {
            if ($item['parent'] !== $parent) {
                continue;
            }

            unset($item['parent']);
            $under = self::children($addressed, $id);

            if ($item['heading'] && $under === []) {
                continue;
            }

            $children[] = $item + ['children' => $under];
        }

        return $children;
    }
}
