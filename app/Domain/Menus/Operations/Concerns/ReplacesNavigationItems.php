<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Concerns;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cards\InternalLinks;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\Operations\SaveNavigationItems;
use Illuminate\Support\Str;

/**
 * How a menu Operation writes a checked tree as a menu's items, in place of
 * the ones it had: an own-site address stored as what it names (rule 9).
 * Used only by the Operations that replace a whole tree, SaveNavigationItems
 * and ImportNavigation, inside their transactions with the menu locked.
 */
trait ReplacesNavigationItems
{
    /**
     * Writes the tree as the menu's items and touches the menu.
     *
     * @param  array<mixed>  $tree
     */
    private function replace(Navigation $navigation, array $tree): void
    {
        NavigationItem::query()->where('navigation_id', $navigation->id)->delete();

        $now = now();
        $ids = [];
        $rows = [];

        // flatten() lists each parent before its children, so the parent's
        // row exists when a child's foreign key is checked.
        foreach (self::flatten($tree) as $item) {
            if ($item['kind'] === 'url') {
                $item = [...$item, ...self::ownSite($item['url'] ?? '')];
            }

            $ids[$item['key']] = strtolower((string) Str::ulid());
            $rows[] = [
                'id' => $ids[$item['key']],
                'navigation_id' => $navigation->id,
                'parent_id' => $item['parent'] === null ? null : $ids[$item['parent']],
                'position' => $item['position'],
                'kind' => $item['kind'],
                'target_id' => in_array($item['kind'], ['content', 'term'], true) ? $item['target_id'] : null,
                'url' => $item['kind'] === 'url' ? $item['url'] : null,
                'label' => $item['label'],
                // A heading opens nothing in a new tab.
                'opens_new_tab' => $item['kind'] !== 'heading' && $item['opens_new_tab'],
                // Only a switcher has options, always as an object.
                'options' => $item['kind'] === 'languages' ? json_encode((object) ($item['options'] ?? []), JSON_THROW_ON_ERROR) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            NavigationItem::query()->insert($chunk);
        }

        $navigation->touch();
    }

    /**
     * Every item in the tree, parents first, each with its place: `key`
     * mirrors the input (`tree.0.children.1`), `path` counts from 1 for the
     * messages, `parent` is the parent's key, and `children` counts the
     * items under it. A blank label is null, meaning the target's own
     * title: the editor sends '' once it's cleared.
     *
     * @param  array<mixed>  $nodes
     * @param  list<int>  $path
     *
     * @return list<array{key: string, parent: string|null, path: list<int>, position: int, kind: string, target_id: string|null, url: string|null, label: string|null, opens_new_tab: bool, children: int, options: array<mixed>|null}>
     */
    private static function flatten(array $nodes, ?string $parent = null, array $path = []): array
    {
        $items = [];
        $position = 0;

        foreach (array_values($nodes) as $index => $node) {
            $node = is_array($node) ? $node : [];
            $key = ($parent ?? 'tree').($parent === null ? '.' : '.children.').$index;
            $label = is_string($node['label'] ?? null) ? trim($node['label']) : '';

            $items[] = [
                'key' => $key,
                'parent' => $parent,
                'path' => [...$path, $index + 1],
                'position' => $position++,
                'kind' => is_string($node['kind'] ?? null) ? $node['kind'] : '',
                'target_id' => is_string($node['target_id'] ?? null) && $node['target_id'] !== '' ? $node['target_id'] : null,
                'url' => is_string($node['url'] ?? null) ? trim($node['url']) : null,
                'label' => $label === '' ? null : $label,
                'opens_new_tab' => filter_var($node['opens_new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'children' => is_array($node['children'] ?? null) ? count($node['children']) : 0,
                'options' => is_array($node['options'] ?? null) ? $node['options'] : null,
            ];

            // One level past the limit is enough to report; deeper isn't walked.
            if (is_array($node['children'] ?? null) && $node['children'] !== [] && count($path) < SaveNavigationItems::MAX_DEPTH) {
                array_push($items, ...self::flatten($node['children'], $key, [...$path, $index + 1]));
            }
        }

        return $items;
    }

    /**
     * What a web address is stored as: this site's own address becomes the
     * home page, the article or the category or tag it names (query and
     * place are kept only on a path, which has room for them), or else a
     * site-relative path; an outside address, an email and a phone link are
     * unchanged. The host is compared in lower case without `www.`.
     *
     * @return array{kind: string, target_id: string|null, url: string|null}
     */
    private static function ownSite(string $url): array
    {
        $asTyped = ['kind' => 'url', 'target_id' => null, 'url' => $url];

        if (preg_match('#^/(?!/)#', $url) !== 1) {
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = preg_replace('/^www\./', '', strtolower((string) ($parts['host'] ?? '')));
            $own = preg_replace('/^www\./', '', strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $host !== $own) {
                return $asTyped;
            }

            $url = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
            $url .= isset($parts['query']) ? '?'.$parts['query'] : '';
            $url .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        }

        if (strcspn($url, '?#') === strlen($url)) {
            $path = PublicRequestPath::normalise($url);

            if ($path === '') {
                return ['kind' => 'home', 'target_id' => null, 'url' => null];
            }

            // A menu item holds an article or a term; an author's or a file's address stays a path.
            $target = app(InternalLinks::class)->targetAt($url);

            if ($target !== null && in_array($target['kind'], ['content', 'term'], true)) {
                return ['kind' => $target['kind'], 'target_id' => $target['id'], 'url' => null];
            }
        }

        return ['kind' => 'url', 'target_id' => null, 'url' => $url];
    }
}
