<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Plugins\Capability;

/**
 * The list columns running plugins offer for each content type: drawn from
 * each plugin's manifest, asked only when a person has turned the column on.
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
}
