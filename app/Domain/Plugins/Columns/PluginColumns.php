<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRow;
use Teme\Contracts\Lists\ListRows;
use Teme\Contracts\Plugins\Capability;
use Throwable;

/**
 * The list columns running plugins offer for each content type: drawn from
 * each plugin's manifest, asked only when a person has turned the column on,
 * under a per-call and per-page time budget.
 */
final class PluginColumns
{
    public const int CALL_BUDGET_MS = 100;

    public const int PAGE_BUDGET_MS = 300;

    public const int MOST_TEXT = 80;

    public const int MOST_BADGE = 24;

    public function __construct(
        private readonly LoadedPlugins $loaded,
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly ColumnClock $clock,
        private readonly Container $container,
    ) {}

    /**
     * The columns offered for this content type, in plugin name order then
     * declared order.
     *
     * @return list<DeclaredColumn>
     */
    public function for(string $type): array
    {
        $running = array_filter(
            $this->loaded->running(),
            fn (object $plugin): bool => $plugin instanceof HasListColumns,
        );

        if ($running === []) {
            return [];
        }

        $found = $this->registry->all();
        $columns = [];

        foreach ($this->records->enabled() as $record) {
            $plugin = $running[$record->name] ?? null;

            if (! $plugin instanceof HasListColumns) {
                continue;
            }

            if (! in_array(Capability::ContentRead->value, $record->consent['capabilities'] ?? [], true)) {
                continue;
            }

            $declared = $found[$record->name]->manifest?->contributes()['columns'] ?? null;

            if (! is_array($declared)) {
                continue;
            }

            foreach ($declared as $entry) {
                if (! is_array($entry) || ! is_string($entry['id'] ?? null)) {
                    continue;
                }

                $types = $entry['types'] ?? null;

                if (is_array($types) && $types !== [] && ! in_array($type, $types, true)) {
                    continue;
                }

                $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : 'text';
                $widthKey = is_string($entry['width'] ?? null) ? $entry['width'] : null;
                $width = ContributionsValidator::COLUMN_WIDTHS[$widthKey]
                    ?? match ($kind) {
                        'text' => ContributionsValidator::COLUMN_WIDTHS['medium'],
                        default => ContributionsValidator::COLUMN_WIDTHS['narrow'],
                    };

                $columns[] = new DeclaredColumn(
                    key: 'plugin:'.$found[$record->name]->code.':'.$entry['id'],
                    owner: $record->name,
                    code: $found[$record->name]->code,
                    id: $entry['id'],
                    label: is_string($entry['label'] ?? null) ? trim($entry['label']) : $entry['id'],
                    plugin: $found[$record->name]->manifest?->label() ?? $found[$record->name]->code,
                    kind: $kind,
                    width: $width,
                );
            }
        }

        return $columns;
    }

    /**
     * The preference keys for this type's offered plugin columns.
     *
     * @return list<string>
     */
    public function keysFor(string $type): array
    {
        return array_map(fn (DeclaredColumn $column): string => $column->key, $this->for($type));
    }

    /**
     * Asks each shown plugin column once for this page's rows, under the
     * time budget, and returns the cells kept plus any plugin problems.
     *
     * @param  list<string>  $shownKeys
     * @param  list<ListRow>  $rows
     */
    public function cells(string $type, array $shownKeys, array $rows): ColumnCells
    {
        if ($rows === [] || $shownKeys === []) {
            return new ColumnCells([], []);
        }

        $byKey = [];
        foreach ($this->for($type) as $column) {
            $byKey[$column->key] = $column;
        }

        $ids = array_fill_keys(array_map(fn (ListRow $row): string => $row->id, $rows), true);
        $listRows = new ListRows($type, array_values($rows));
        $running = $this->loaded->running();
        $spent = 0;
        $values = [];
        $problems = [];

        foreach ($shownKeys as $key) {
            $column = $byKey[$key] ?? null;
            if ($column === null) {
                continue;
            }

            if ($spent >= self::PAGE_BUDGET_MS * 1_000_000) {
                $problems[$column->plugin] = true;

                continue;
            }

            $started = $this->clock->nanoseconds();

            try {
                $handler = $running[$column->owner]->listColumns()[$column->id] ?? null;
                if (! $handler instanceof ListColumn) {
                    throw new ColumnAnswerRefused("{$column->key} has no ListColumn.");
                }
                $given = $handler->cells($listRows);
            } catch (Throwable $failure) {
                $spent += $this->clock->nanoseconds() - $started;
                $this->fault($column, $failure);
                $problems[$column->plugin] = true;

                continue;
            }

            $took = $this->clock->nanoseconds() - $started;
            $spent += $took;

            if ($took > self::CALL_BUDGET_MS * 1_000_000) {
                $this->fault($column, new ColumnTooSlow($column->key, intdiv($took, 1_000_000)));
                $problems[$column->plugin] = true;

                continue;
            }

            $values[$column->key] = $this->kept($column, $given, $ids);
        }

        return new ColumnCells($values, array_keys($problems));
    }

    /**
     * @param  array<string, true>  $ids
     *
     * @return array<string, array{kind: string, value: string|int|float, tone?: string}>
     */
    private function kept(DeclaredColumn $column, mixed $given, array $ids): array
    {
        $kept = [];
        $refused = false;

        if (! is_array($given)) {
            $this->fault($column, new ColumnAnswerRefused("{$column->key} answered with the wrong shape."));

            return [];
        }

        foreach ($given as $id => $cell) {
            if (! is_string($id) || ! isset($ids[$id]) || ! $cell instanceof Cell || $cell->kind !== $column->kind) {
                $refused = true;

                continue;
            }

            if ($column->kind === Cell::NUMBER) {
                if (! is_finite((float) $cell->value)) {
                    $refused = true;

                    continue;
                }

                $kept[$id] = ['kind' => Cell::NUMBER, 'value' => $cell->value];

                continue;
            }

            $clean = preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', (string) $cell->value);
            if ($clean === null) {
                $refused = true;

                continue;
            }

            $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
            if ($clean === '') {
                continue;
            }

            $most = $column->kind === Cell::BADGE ? self::MOST_BADGE : self::MOST_TEXT;
            if (mb_strlen($clean) > $most) {
                $clean = mb_substr($clean, 0, $most - 1).'…';
            }

            $kept[$id] = $column->kind === Cell::BADGE
                ? ['kind' => Cell::BADGE, 'value' => $clean, 'tone' => $cell->tone ?? 'neutral']
                : ['kind' => Cell::TEXT, 'value' => $clean];
        }

        if ($refused) {
            $this->fault($column, new ColumnAnswerRefused("{$column->key} answered with the wrong shape."));
        }

        return $kept;
    }

    private function fault(DeclaredColumn $column, Throwable $failure): void
    {
        try {
            $this->container->make(ListenerFaults::class)->record(
                $column->owner,
                "{$column->owner}#column:{$column->id}",
                ListColumn::class,
                $failure,
            );
        } catch (Throwable) {
            //
        }
    }
}
