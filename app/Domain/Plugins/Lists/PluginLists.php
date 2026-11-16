<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\CellText;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Columns\PluginColumns;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\HasPluginLists;
use Teme\Contracts\Lists\PluginList;
use Throwable;

/**
 * The lists a running plugin shows on its own settings page
 * (docs/proposals/plugin-sockets-1.md): each asked once as the page is
 * drawn, under a time budget a list and a page, its cells cleaned as list
 * columns' are. A list that throws, runs over or answers wrongly is
 * recorded under the plugin and shows a line instead.
 */
final readonly class PluginLists
{
    public const int LIST_BUDGET_MS = 100;

    public const int PAGE_BUDGET_MS = 250;

    public function __construct(
        private LoadedPlugins $loaded,
        private PluginRecords $records,
        private PluginRegistry $registry,
        private ColumnClock $clock,
        private Container $container,
    ) {}

    /**
     * Returns the lists of the plugin whose settings group this is, each with
     * its columns and its cleaned rows, its words in the current locale, or with the line saying it couldn't
     * be shown; none for a group that isn't a running plugin's.
     *
     * @return list<array{id: string, label: string, description: string|null, columns: list<array{id: string, label: string, kind: string}>, rows: list<array<string, array{kind: string, value: string|int|float, tone?: string}>>, failed: string|null}>
     */
    public function forGroup(string $group): array
    {
        $declared = $this->declared($group);

        if ($declared === []) {
            return [];
        }

        $plugin = $this->loaded->running()[$declared[0]->owner] ?? null;
        $handlers = [];

        $spent = 0;
        $shown = [];

        foreach ($declared as $list) {
            if ($spent >= self::PAGE_BUDGET_MS * 1_000_000) {
                $this->fault($list, new ListTooSlow($list->id, intdiv($spent, 1_000_000)));
                $shown[] = $this->failed($list);

                continue;
            }

            $started = $this->clock->nanoseconds();

            try {
                if ($handlers === [] && $plugin instanceof HasPluginLists) {
                    $handlers = $plugin->pluginLists();
                }

                $handler = $handlers[$list->id] ?? null;

                if (! $handler instanceof PluginList) {
                    throw new ListAnswerRefused("The plugin has no list {$list->id}.");
                }

                $given = $handler->rows($list->rows);
            } catch (Throwable $failure) {
                $spent += $this->clock->nanoseconds() - $started;
                $this->fault($list, $failure);
                $shown[] = $this->failed($list);

                continue;
            }

            $took = $this->clock->nanoseconds() - $started;
            $spent += $took;

            if ($took > self::LIST_BUDGET_MS * 1_000_000) {
                $this->fault($list, new ListTooSlow($list->id, intdiv($took, 1_000_000)));
                $shown[] = $this->failed($list);

                continue;
            }

            $shown[] = [...$this->shape($list), 'rows' => $this->kept($list, $given), 'failed' => null];
        }

        // Its headings in the person's language, from its own lang folder; rows are values, never translated.
        $words = ManifestText::for(substr($group, strlen('plugin.')));
        $locale = app()->getLocale();

        return array_map(fn (array $list): array => [
            ...$list,
            'label' => $words->text($list['label'], $locale),
            'description' => $list['description'] === null ? null : $words->text($list['description'], $locale),
            'columns' => array_map(fn (array $column): array => [...$column, 'label' => $words->text($column['label'], $locale)], $list['columns']),
        ], $shown);
    }

    /**
     * The lists the plugin owning this settings group declares, when it is
     * enabled and running.
     *
     * @return list<DeclaredList>
     */
    private function declared(string $group): array
    {
        if (! str_starts_with($group, 'plugin.')) {
            return [];
        }

        $code = substr($group, strlen('plugin.'));
        $running = $this->loaded->running();

        foreach ($this->records->enabled() as $record) {
            if ($record->code !== $code || ! isset($running[$record->name])) {
                continue;
            }

            $found = $this->registry->find($record->name);
            $lists = $found instanceof FoundPlugin ? ($found->manifest?->contributes()['lists'] ?? null) : null;

            return is_array($lists) ? $this->read($record->name, $lists) : [];
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $lists
     *
     * @return list<DeclaredList>
     */
    private function read(string $owner, array $lists): array
    {
        $declared = [];

        foreach ($lists as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_array($entry['columns'] ?? null)) {
                continue;
            }

            $columns = [];

            foreach ($entry['columns'] as $column) {
                if (is_array($column) && is_string($column['id'] ?? null) && is_string($column['label'] ?? null) && is_string($column['kind'] ?? null)) {
                    $columns[] = ['id' => $column['id'], 'label' => trim($column['label']), 'kind' => $column['kind']];
                }
            }

            $declared[] = new DeclaredList(
                owner: $owner,
                id: $entry['id'],
                label: is_string($entry['label'] ?? null) ? trim($entry['label']) : $entry['id'],
                description: is_string($entry['description'] ?? null) ? $entry['description'] : null,
                columns: $columns,
                rows: is_int($entry['rows'] ?? null) ? $entry['rows'] : ContributionsValidator::DEFAULT_LIST_ROWS,
            );
        }

        return $declared;
    }

    /**
     * The rows kept: at most the declared number, each cell of its column's
     * kind and cleaned; a cell of another kind or an unknown column is left
     * out and the answer recorded as refused.
     *
     * @return list<array<string, array{kind: string, value: string|int|float, tone?: string}>>
     */
    private function kept(DeclaredList $list, mixed $given): array
    {
        if (! is_array($given) || ! array_is_list($given)) {
            $this->fault($list, new ListAnswerRefused("The list {$list->id} answered with the wrong shape."));

            return [];
        }

        $kinds = array_column($list->columns, 'kind', 'id');
        $rows = [];
        $refused = false;

        foreach (array_slice($given, 0, $list->rows) as $row) {
            if (! is_array($row)) {
                $refused = true;

                continue;
            }

            $kept = [];

            foreach ($row as $id => $cell) {
                $kind = $kinds[$id] ?? null;

                if ($kind === null || ! $cell instanceof Cell || $cell->kind !== $kind) {
                    $refused = true;

                    continue;
                }

                if ($kind === Cell::NUMBER) {
                    $kept[$id] = ['kind' => Cell::NUMBER, 'value' => $cell->value];

                    continue;
                }

                $clean = CellText::clean((string) $cell->value, $kind === Cell::BADGE ? PluginColumns::MOST_BADGE : PluginColumns::MOST_TEXT);

                if ($clean === null) {
                    $refused = true;

                    continue;
                }

                if ($clean === '') {
                    continue;
                }

                $kept[$id] = $kind === Cell::BADGE
                    ? ['kind' => Cell::BADGE, 'value' => $clean, 'tone' => $cell->tone ?? 'neutral']
                    : ['kind' => Cell::TEXT, 'value' => $clean];
            }

            $rows[] = $kept;
        }

        if ($refused || count($given) > $list->rows) {
            $this->fault($list, new ListAnswerRefused("The list {$list->id} answered with the wrong shape."));
        }

        return $rows;
    }

    /**
     * @return array{id: string, label: string, description: string|null, columns: list<array{id: string, label: string, kind: string}>}
     */
    private function shape(DeclaredList $list): array
    {
        return ['id' => $list->id, 'label' => $list->label, 'description' => $list->description, 'columns' => $list->columns];
    }

    /**
     * @return array{id: string, label: string, description: string|null, columns: list<array{id: string, label: string, kind: string}>, rows: list<array<string, array{kind: string, value: string|int|float, tone?: string}>>, failed: string}
     */
    private function failed(DeclaredList $list): array
    {
        return [...$this->shape($list), 'rows' => [], 'failed' => __('This list couldn’t be shown this time.')];
    }

    private function fault(DeclaredList $list, Throwable $failure): void
    {
        try {
            $this->container->make(ListenerFaults::class)->record($list->owner, "{$list->owner}#list:{$list->id}", PluginList::class, $failure);
        } catch (Throwable) {
            // A fault that can't be recorded never stops the settings page.
        }
    }
}
