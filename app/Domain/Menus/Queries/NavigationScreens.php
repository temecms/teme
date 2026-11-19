<?php

/**
 * What Content › Navigation shows (I-098): the menus with where each is
 * shown, and one menu's tree for its editor, each item with what it points
 * at in words and, when it has no address, why it isn't shown. Slots are the
 * active theme's. A fixed number of queries, however long the menu.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Queries;

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

final readonly class NavigationScreens
{
    public function __construct(
        private ActiveTheme $active,
        private ThemeSlotSettings $slots,
        private ContentTypeRegistry $types,
        private TaxonomyRegistry $taxonomies,
    ) {}

    /**
     * Every menu, by name, with its item count and the labels of the
     * active theme's slots that show it, such as ["Header"].
     *
     * @return list<array{id: string, name: string, handle: string, itemCount: int, shownIn: list<string>}>
     */
    public function index(): array
    {
        $counts = DB::table('navigation_items')->selectRaw('navigation_id, count(*) as items')->groupBy('navigation_id')->pluck('items', 'navigation_id');
        $shown = $this->shownIn();

        return Navigation::query()->orderBy('name')->orderBy('id')->get()
            ->map(fn (Navigation $navigation): array => [
                'id' => $navigation->id,
                'name' => $navigation->name,
                'handle' => $navigation->handle,
                'itemCount' => (int) ($counts[$navigation->id] ?? 0),
                'shownIn' => $shown[$navigation->id] ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * One menu for its editor, or null when there is none: its tree, the
     * active theme's slots with the menu each shows, every menu to choose
     * from for them, and the public types and taxonomies an item may point at.
     *
     * @return array{navigation: array{id: string, name: string, handle: string}, items: list<array<string, mixed>>, themeCode: string|null, themeName: string|null, slots: list<array{slot: string, label: string, navigationId: string|null}>, menus: list<array{id: string, name: string}>, types: list<array{key: string, singular: string, plural: string}>, taxonomies: list<array{key: string, singular: string, plural: string}>}|null
     */
    public function editor(string $id): ?array
    {
        $navigation = Navigation::query()->find($id);

        if ($navigation === null) {
            return null;
        }

        $theme = $this->active->get();
        $labels = $theme?->manifest?->navigations() ?? [];
        $bound = $theme === null ? [] : $this->slots->bound($theme);

        return [
            'navigation' => ['id' => $navigation->id, 'name' => $navigation->name, 'handle' => $navigation->handle],
            'items' => $this->tree($navigation->id),
            'themeCode' => $theme !== null && $labels !== [] ? $theme->code : null,
            'themeName' => $theme?->manifest?->name(),
            'slots' => array_map(
                fn (string $slot, string $label): array => ['slot' => $slot, 'label' => $label, 'navigationId' => $bound[$slot] ?? null],
                array_keys($labels),
                $labels,
            ),
            'menus' => Navigation::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Navigation $menu): array => ['id' => $menu->id, 'name' => $menu->name])->values()->all(),
            'types' => array_values(array_map(
                fn ($type): array => ['key' => $type->key, 'singular' => $type->singular, 'plural' => $type->plural],
                array_filter($this->types->all(), fn ($type): bool => $type->public),
            )),
            'taxonomies' => array_values(array_map(
                fn ($taxonomy): array => ['key' => $taxonomy->key, 'singular' => $taxonomy->singular, 'plural' => $taxonomy->plural],
                array_filter($this->taxonomies->all(), fn ($taxonomy): bool => $taxonomy->public),
            )),
        ];
    }

    /**
     * What a place of the active theme now shows, for the toast after its
     * menu is chosen: the place's label, its key when the theme gives none,
     * and the chosen menu's name, or null for none.
     *
     * @return array{label: string, menu: string|null}
     */
    public function place(string $slot, ?string $navigationId): array
    {
        $labels = $this->active->get()?->manifest?->navigations() ?? [];
        $name = $navigationId === null ? null : Navigation::query()->whereKey($navigationId)->value('name');

        return ['label' => $labels[$slot] ?? $slot, 'menu' => is_string($name) ? $name : null];
    }

    /**
     * Menu ID => the labels of the active theme's slots that show it.
     *
     * @return array<string, list<string>>
     */
    private function shownIn(): array
    {
        $theme = $this->active->get();

        if ($theme === null) {
            return [];
        }

        $labels = $theme->manifest?->navigations() ?? [];
        $shown = [];

        foreach ($this->slots->bound($theme) as $slot => $navigationId) {
            if ($navigationId !== null) {
                $shown[$navigationId][] = $labels[$slot];
            }
        }

        return $shown;
    }

    /**
     * The menu's items as a tree, each with its target in words and why it
     * isn't shown, if it isn't: four queries for the whole menu.
     *
     * @return list<array<string, mixed>>
     */
    private function tree(string $navigationId): array
    {
        $items = DB::table('navigation_items')->where('navigation_id', $navigationId)->orderBy('position')
            ->get(['id', 'parent_id', 'kind', 'target_id', 'url', 'label', 'opens_new_tab']);

        $contentIds = $items->where('kind', 'content')->pluck('target_id')->unique()->values()->all();
        $termIds = $items->where('kind', 'term')->pluck('target_id')->unique()->values()->all();

        $contents = $contentIds === [] ? collect() : DB::table('contents')->whereIn('id', $contentIds)
            ->get(['id', 'type', 'title', 'status', 'published_at', 'deleted_at'])->keyBy('id');
        $terms = $termIds === [] ? collect() : DB::table('terms')->whereIn('id', $termIds)->get(['id', 'taxonomy', 'name'])->keyBy('id');
        $addressed = ($contentIds === [] && $termIds === []) ? [] : DB::table('paths')->where('is_current', true)
            ->where(fn ($query) => $query
                ->where(fn ($content) => $content->where('target_type', 'content')->whereIn('target_id', $contentIds))
                ->orWhere(fn ($term) => $term->where('target_type', 'term')->whereIn('target_id', $termIds)))
            ->get(['target_type', 'target_id'])
            ->map(fn (object $row): string => "{$row->target_type}:{$row->target_id}")
            ->all();

        $rows = [];

        foreach ($items as $item) {
            [$target, $warning] = $this->describe($item, $contents->get($item->target_id), $terms->get($item->target_id), $addressed);

            $rows[(string) $item->id] = [
                'parent' => $item->parent_id === null ? null : (string) $item->parent_id,
                'kind' => (string) $item->kind,
                'targetId' => $item->target_id === null ? null : (string) $item->target_id,
                'url' => $item->url === null ? null : (string) $item->url,
                'label' => $item->label === null ? null : (string) $item->label,
                'opensNewTab' => (bool) $item->opens_new_tab,
                'target' => $target,
                'warning' => $warning,
            ];
        }

        return self::children($rows, null);
    }

    /**
     * What an item points at, in words, such as "Page: About", and why it
     * isn't shown, or null when it is.
     *
     * @param  list<string>  $addressed
     *
     * @return array{0: string, 1: string|null}
     */
    private function describe(object $item, ?object $content, ?object $term, array $addressed): array
    {
        if ($item->kind === 'home') {
            return [__('Home'), null];
        }

        if ($item->kind === 'heading') {
            return [__('Heading'), null];
        }

        if ($item->kind === 'languages') {
            return [__('Language switcher'), null];
        }

        if ($item->kind === 'url') {
            return [(string) $item->url, null];
        }

        if ($item->kind === 'term') {
            if ($term === null) {
                return [__('A deleted category or tag'), __('Not shown: what it points at was deleted')];
            }

            $singular = $this->taxonomies->has((string) $term->taxonomy) ? $this->taxonomies->get((string) $term->taxonomy)->singular : __('Term');

            return ["{$singular}: {$term->name}", in_array("term:{$term->id}", $addressed, true) ? null : __('Not shown: the :singular has no address', ['singular' => mb_strtolower($singular)])];
        }

        if ($content === null) {
            return [__('A deleted item'), __('Not shown: what it points at was deleted')];
        }

        $singular = $this->types->has((string) $content->type) ? $this->types->get((string) $content->type)->singular : __('Item');
        $lower = mb_strtolower($singular);

        $warning = match (true) {
            $content->deleted_at !== null => __('Not shown: the :singular is in the trash', ['singular' => $lower]),
            $content->status !== 'published' || $content->published_at === null || strtotime((string) $content->published_at) > time() => __('Not shown: the :singular isn\'t published', ['singular' => $lower]),
            ! in_array("content:{$content->id}", $addressed, true) => __('Not shown: the :singular has no address', ['singular' => $lower]),
            default => null,
        };

        return ["{$singular}: {$content->title}", $warning];
    }

    /**
     * The rows under one parent, in order, each with its own. A heading
     * none of whose items is shown isn't shown either, and says so (I-2628).
     *
     * @param  array<string, array<string, mixed>>  $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function children(array $rows, ?string $parent): array
    {
        $children = [];

        foreach ($rows as $id => $row) {
            if ($row['parent'] !== $parent) {
                continue;
            }

            unset($row['parent']);
            $row['children'] = self::children($rows, $id);

            if ($row['kind'] === 'heading' && array_filter($row['children'], fn (array $child): bool => $child['warning'] === null) === []) {
                $row['warning'] = __('Not shown: nothing under it is shown');
            }

            $children[] = $row;
        }

        return $children;
    }
}
