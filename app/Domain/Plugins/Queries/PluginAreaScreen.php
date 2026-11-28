<?php

/**
 * One page of a plugin's area as its screen needs it
 * (docs/plans/plugin-sockets-2.md, A6, A9): the plugin, the area's pages,
 * and each block's declaration, its words through AreaWords. Each block's
 * data is read on its own, under AreaReads' budgets, so the screen can
 * defer it and one slow block never holds the page.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Extensions\PackagePictures;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\AreaValidator;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\PluginRegistry;
use App\Models\User;
use Closure;
use Teme\Contracts\Admin\TableQuery;

/**
 * @phpstan-type TableAsk array{page?: mixed, search?: mixed, filters?: mixed, sort?: mixed, direction?: mixed}
 */
final readonly class PluginAreaScreen
{
    public function __construct(
        private PluginAreas $areas,
        private AreaReads $reads,
        private PluginActions $actions,
        private PluginRegistry $registry,
    ) {}

    /**
     * The plugin with this code and an area, when this person may open it;
     * null otherwise.
     */
    public function plugin(string $code, User $user): ?AreaPlugin
    {
        $plugin = $this->areas->find($code);

        return $plugin !== null && $user->can($plugin->ability()) ? $plugin : null;
    }

    /**
     * The page's frame: the plugin's code and name, the area's label and
     * description, its pages (the current marked), and the page's blocks as
     * declared, each with what it draws before its data arrives. Null for a
     * page the area doesn't declare. Pass settingsTab when the person may
     * see the Settings tab; settingsCurrent when that tab is open.
     *
     * @return array{plugin: array{code: string, name: string, icon: string|null}, area: array{label: string, description: string}, tabs: list<array{id: string, label: string, current: bool, href?: string}>, current: array{id: string, label: string}, blocks: list<array<string, mixed>>}|null
     */
    public function frame(AreaPlugin $plugin, ?string $page, bool $settingsTab = false, bool $settingsCurrent = false): ?array
    {
        $words = fn (string $english): string => AreaWords::text($plugin->code, $english);

        if ($settingsCurrent) {
            $current = ['id' => 'settings', 'label' => __('Settings'), 'blocks' => []];
        } else {
            $current = $plugin->area->page($page);

            if ($current === null) {
                return null;
            }
        }

        $blocks = [];

        foreach ($current['blocks'] as $block) {
            $blocks[] = $this->blockFrame($plugin, $block, $words);
        }

        $tabs = array_map(
            fn (array $one): array => ['id' => $one['id'], 'label' => $words($one['label']), 'current' => ! $settingsCurrent && $one['id'] === $current['id']],
            $plugin->area->pages,
        );

        if ($settingsTab) {
            $tabs[] = [
                'id' => 'settings',
                'label' => __('Settings'),
                'current' => $settingsCurrent,
                'href' => route('plugins.area.settings', ['code' => $plugin->code], false),
            ];
        }

        return [
            'plugin' => ['code' => $plugin->code, 'name' => $plugin->label, 'icon' => $this->iconUrl($plugin)],
            'area' => ['label' => $words($plugin->area->label), 'description' => $words($plugin->area->description)],
            'tabs' => $tabs,
            'current' => ['id' => $current['id'], 'label' => $settingsCurrent ? __('Settings') : $words($current['label'])],
            'blocks' => $blocks,
        ];
    }

    /**
     * The address of the plugin's own icon for the area header, or null when
     * the package declares none or the file is missing.
     */
    private function iconUrl(AreaPlugin $plugin): ?string
    {
        $found = $this->registry->find($plugin->name);
        $manifest = $found?->manifest;

        if ($found === null || $manifest === null || PackagePictures::find($found->path, $manifest->icon()) === null) {
            return null;
        }

        return route('plugins.area.icon', ['code' => $plugin->code, 'v' => $manifest->version()]);
    }

    /**
     * A block's data, read from the plugin under the budgets: a figures
     * block's items, a chart's labels and series, a table's rows; null for
     * a block that needs none.
     *
     * @param  TableAsk  $ask  what the table's search, filters, sort and page ask
     *
     * @return array<string, mixed>|null
     */
    public function data(AreaPlugin $plugin, string $page, string $block, string $personId, array $ask = []): ?array
    {
        $declared = $plugin->area->block($page, $block);
        $context = AreaContexts::for($plugin->code, $personId);

        return match ($declared['type'] ?? null) {
            'figures' => $this->reads->figures($plugin, $page, $declared, $context),
            'chart' => $this->reads->chart($plugin, $page, $declared, $context),
            'table' => $this->reads->rows($plugin, $declared, $this->query($declared, $ask), $context),
            default => null,
        };
    }

    /**
     * One block of a Home panel: its frame as the area draws it, and its data
     * read under the budgets. A table on Home shows its first five rows,
     * read only (docs/guides/developers/plugins/home-panels.md).
     *
     * @param  array<string, mixed>  $declared  the block as the area declares it
     *
     * @return array<string, mixed>
     */
    public function homeBlock(AreaPlugin $plugin, string $page, array $declared, string $personId): array
    {
        $words = fn (string $english): string => AreaWords::text($plugin->code, $english);
        $frame = $this->blockFrame($plugin, $declared, $words);
        $id = is_string($declared['id'] ?? null) ? $declared['id'] : '';
        $context = AreaContexts::for($plugin->code, $personId);

        $data = match ($frame['type']) {
            'figures' => $this->reads->figures($plugin, $page, $declared, $context),
            'chart' => $this->reads->chart($plugin, $page, $declared, $context),
            'table' => $this->reads->rows($plugin, $declared, $this->query(['perPage' => 5], []), $context),
            default => null,
        };

        return [...$frame, 'id' => $id, 'data' => $data];
    }

    /**
     * What a table asks of the plugin, from the request, every value held
     * to the declaration: at most the declared rows a page, page 1,000 at
     * most, a search of 100 characters, declared filters and options, and a
     * sortable column.
     *
     * @param  array<string, mixed>  $table
     * @param  TableAsk  $ask
     */
    public function query(array $table, array $ask): TableQuery
    {
        $perPage = is_int($table['perPage'] ?? null) ? min($table['perPage'], AreaValidator::MOST_PER_PAGE) : 20;
        $page = is_numeric($ask['page'] ?? null) ? max(1, min(AreaReads::MOST_PAGE, (int) $ask['page'])) : 1;
        $search = ($table['search'] ?? false) === true && is_string($ask['search'] ?? null) ? mb_substr(trim($ask['search']), 0, AreaReads::MOST_SEARCH) : '';
        $filters = [];

        foreach (is_array($table['filters'] ?? null) ? $table['filters'] : [] as $filter) {
            $id = is_array($filter) && is_string($filter['id'] ?? null) ? $filter['id'] : null;
            $chosen = is_array($ask['filters'] ?? null) ? ($ask['filters'][$id] ?? null) : null;

            if ($id !== null && is_string($chosen) && is_array($filter['options'] ?? null) && array_key_exists($chosen, $filter['options'])) {
                $filters[$id] = $chosen;
            }
        }

        $sortable = [];

        foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column) {
            if (is_array($column) && ($column['sortable'] ?? false) === true && is_string($column['id'] ?? null)) {
                $sortable[] = $column['id'];
            }
        }

        $sort = in_array($ask['sort'] ?? null, $sortable, true) ? (string) $ask['sort'] : null;

        return new TableQuery($page, max(1, $perPage), $search, $filters, $sort, ($ask['direction'] ?? null) === 'asc' ? 'asc' : 'desc');
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Closure(string): string  $words
     *
     * @return array<string, mixed>
     */
    private function blockFrame(AreaPlugin $plugin, array $block, Closure $words): array
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        $frame = ['id' => is_string($block['id'] ?? null) ? $block['id'] : '', 'type' => $type];
        $text = fn (mixed $value): string => $words(is_string($value) ? $value : '');
        $entries = fn (string $key): array => array_values(array_filter(is_array($block[$key] ?? null) ? $block[$key] : [], is_array(...)));

        if ($type === 'figures') {
            $frame['items'] = array_map(fn (array $item): array => ['id' => (string) ($item['id'] ?? ''), 'label' => $text($item['label'] ?? null), 'format' => (string) ($item['format'] ?? 'number')], $entries('items'));
        } elseif ($type === 'chart') {
            $frame['label'] = $text($block['label'] ?? null);
            $frame['kind'] = (string) ($block['kind'] ?? 'line');
            $frame['series'] = array_map(fn (array $one): array => ['id' => (string) ($one['id'] ?? ''), 'label' => $text($one['label'] ?? null)], $entries('series'));
        } elseif ($type === 'table') {
            $record = $plugin->area->record(is_string($block['record'] ?? null) ? $block['record'] : '');
            $frame['record'] = ['id' => $record['id'] ?? '', 'label' => $text($record['label'] ?? null), 'plural' => $text($record['plural'] ?? null)];
            $frame['columns'] = array_map(fn (array $column): array => ['id' => (string) ($column['id'] ?? ''), 'label' => $text($column['label'] ?? null), 'kind' => (string) ($column['kind'] ?? 'text'), 'sortable' => ($column['sortable'] ?? false) === true], $entries('columns'));
            $frame['search'] = ($block['search'] ?? false) === true;
            $frame['filters'] = array_map(fn (array $filter): array => [
                'id' => (string) ($filter['id'] ?? ''),
                'label' => $text($filter['label'] ?? null),
                'options' => array_map(fn (mixed $value, mixed $label): array => ['value' => (string) $value, 'label' => $text($label)], array_keys(is_array($filter['options'] ?? null) ? $filter['options'] : []), array_values(is_array($filter['options'] ?? null) ? $filter['options'] : [])),
            ], $entries('filters'));
            $frame['perPage'] = is_int($block['perPage'] ?? null) ? $block['perPage'] : 20;
            $frame['rowActions'] = array_values(array_filter(is_array($block['rowActions'] ?? null) ? $block['rowActions'] : [], is_string(...)));
            $frame['bulkActions'] = array_values(array_filter(is_array($block['bulkActions'] ?? null) ? $block['bulkActions'] : [], is_string(...)));
            $custom = [];

            foreach ($frame['rowActions'] as $actionId) {
                if (in_array($actionId, ['edit', 'duplicate', 'enable', 'disable', 'delete'], true)) {
                    continue;
                }

                $action = $this->actions->find($plugin->code, $actionId);

                if ($action === null || $action['row'] !== true) {
                    continue;
                }

                $custom[] = [
                    'id' => $action['id'],
                    'label' => $words($action['label']),
                    'confirm' => $action['confirm'] === null ? null : $words($action['confirm']),
                    'icon' => $action['icon'],
                ];
            }

            $frame['customActions'] = $custom;
        } elseif ($type === 'actions') {
            $offered = [];

            foreach (is_array($block['actions'] ?? null) ? $block['actions'] : [] as $id) {
                $action = is_string($id) ? $this->actions->find($plugin->code, $id) : null;

                if ($action !== null) {
                    $offered[] = ['id' => $action['id'], 'label' => $words($action['label']), 'description' => $action['description'] === null ? null : $words($action['description'])];
                }
            }

            $frame['actions'] = $offered;
        } elseif ($type === 'notice') {
            $frame['text'] = $text($block['text'] ?? null);
            $frame['tone'] = is_string($block['tone'] ?? null) ? $block['tone'] : 'neutral';
        }

        return $frame;
    }
}
