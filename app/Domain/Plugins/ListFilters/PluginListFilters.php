<?php

/**
 * The list filters enabled plugins offer for each content type. What is offered
 * is the snapshot recorded with the plugin's consent, the filters as they were
 * checked at enable or at its database changes, never a manifest nobody checked.
 * Also the refusal that keeps a list to three filters, across every plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\ListFilters;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRecords;
use Throwable;

final readonly class PluginListFilters
{
    /** At most this many filters on one content list, across every plugin (issue 802). */
    public const int MOST_PER_LIST = 3;

    public function __construct(
        private PluginRecords $records,
        private ListFilterIndexes $indexes,
    ) {}

    /**
     * The filters offered on this content type's list, from each enabled plugin
     * in name order then its declared order, at most MOST_PER_LIST. A filter whose
     * table or columns are gone is recorded as a fault and left out.
     *
     * @return list<DeclaredListFilter>
     */
    public function forType(string $type): array
    {
        $filters = [];

        foreach ($this->records->enabled() as $record) {
            $words = ManifestText::for($record->code);
            $locale = app()->getLocale();

            foreach ($this->snapshot($record) as $entry) {
                if (! in_array($type, $entry['types'], true)) {
                    continue;
                }

                $key = 'plugin:'.$record->code.':'.$entry['id'];

                if (! $this->indexes->stillThere($entry['table'], $entry['column'], $entry['item'])) {
                    $this->fault($record->name, $key, new ListFilterUnavailable($key, $entry['table']));

                    continue;
                }

                $options = [];

                foreach ($entry['options'] as $option) {
                    $options[$option['value']] = $words->text($option['label'], $locale);
                }

                $filters[] = new DeclaredListFilter(
                    key: $key,
                    owner: $record->name,
                    label: $words->text($entry['label'], $locale),
                    options: $options,
                    table: $entry['table'],
                    column: $entry['column'],
                    item: $entry['item'],
                );
            }
        }

        return array_slice($filters, 0, self::MOST_PER_LIST);
    }

    /**
     * The entries a manifest declares, normalised to the keys the snapshot keeps:
     * what is recorded with the plugin's consent when it is checked.
     *
     * @return list<array{id: string, label: string, types: list<string>, options: list<array{value: string, label: string}>, table: string, column: string, item: string}>
     */
    public static function declared(Manifest $manifest): array
    {
        $declared = [];

        foreach ($manifest->contributes()['listFilters'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $declared[] = [
                'id' => (string) $entry['id'],
                'label' => (string) $entry['label'],
                'types' => array_values($entry['types']),
                'options' => array_map(fn (array $option): array => ['value' => (string) $option['value'], 'label' => (string) $option['label']], array_values($entry['options'])),
                'table' => (string) $entry['table'],
                'column' => (string) $entry['column'],
                'item' => (string) $entry['item'],
            ];
        }

        return $declared;
    }

    /**
     * The sentence that refuses checking $declared for the plugin $name when it
     * would put more than MOST_PER_LIST filters on one content type's list beside
     * the filters of the other $enabled plugins' recorded snapshots; null when they fit.
     *
     * @param  list<array{types: list<string>}>  $declared
     * @param  list<PluginRecord>  $enabled
     */
    public function refusalBeside(array $declared, string $name, array $enabled): ?string
    {
        $own = [];

        foreach ($declared as $entry) {
            foreach ($entry['types'] as $type) {
                $own[$type] = ($own[$type] ?? 0) + 1;
            }
        }

        foreach ($own as $type => $count) {
            $beside = 0;

            foreach ($enabled as $record) {
                if ($record->name === $name) {
                    continue;
                }

                $beside += count(array_filter($this->snapshot($record), fn (array $entry): bool => in_array($type, $entry['types'], true)));
            }

            if ($beside + $count > self::MOST_PER_LIST) {
                return __('A :type list shows at most :most filters, and enabling this plugin would make :count.', [
                    'type' => $type,
                    'most' => self::MOST_PER_LIST,
                    'count' => $beside + $count,
                ]);
            }
        }

        return null;
    }

    /**
     * The checked filters a record holds; none for a plugin that declares none.
     *
     * @return list<array{id: string, label: string, types: list<string>, options: list<array{value: string, label: string}>, table: string, column: string, item: string}>
     */
    private function snapshot(PluginRecord $record): array
    {
        $entries = $record->consent['listFilters'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, is_array(...))) : [];
    }

    private function fault(string $owner, string $key, Throwable $failure): void
    {
        try {
            app(ListenerFaults::class)->record($owner, $key, 'list-filter', $failure);
        } catch (Throwable) {
            //
        }
    }
}
