<?php

/**
 * The list filters enabled plugins declare for a content type, read from their
 * manifests, and the refusal that keeps a list to three filters. A filter reads
 * only the declared options, matched exactly against a column the plugin owns.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\ListFilters;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;

final readonly class PluginListFilters
{
    /** At most this many filters on one content list, across every plugin (issue 802). */
    public const int MOST_PER_LIST = 3;

    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
    ) {}

    /**
     * The filters offered on this content type's list, from each enabled
     * plugin in name order then declared order; at most MOST_PER_LIST.
     *
     * @return list<DeclaredListFilter>
     */
    public function forType(string $type): array
    {
        $found = $this->registry->all();
        $filters = [];

        foreach ($this->records->enabled() as $record) {
            $plugin = $found[$record->name] ?? null;
            $manifest = $plugin?->manifest;

            if ($plugin === null || $manifest === null) {
                continue;
            }

            $code = $plugin->code;
            $words = ManifestText::for($code);
            $locale = app()->getLocale();

            foreach ($manifest->contributes()['listFilters'] ?? [] as $entry) {
                if (! is_array($entry) || ! in_array($type, $entry['types'] ?? [], true)) {
                    continue;
                }

                $options = [];

                foreach ($entry['options'] as $option) {
                    $options[$option['value']] = $words->text($option['label'], $locale);
                }

                $filters[] = new DeclaredListFilter(
                    key: 'plugin:'.$code.':'.$entry['id'],
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
     * The sentence that refuses enabling $manifest when the filters it declares
     * would put more than MOST_PER_LIST on one content type's list beside those
     * of the $others; null when they fit.
     *
     * @param  list<Manifest>  $others
     */
    public function refusalBeside(Manifest $manifest, array $others): ?string
    {
        $own = $this->declaredTypes($manifest);

        foreach ($own as $type => $count) {
            $beside = 0;

            foreach ($others as $other) {
                $beside += count(array_filter($this->declaredFilters($other), fn (array $entry): bool => in_array($type, $entry['types'] ?? [], true)));
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
     * How many of the manifest's filters apply to each content type, by key.
     *
     * @return array<string, int>
     */
    private function declaredTypes(Manifest $manifest): array
    {
        $counts = [];

        foreach ($this->declaredFilters($manifest) as $entry) {
            foreach ($entry['types'] ?? [] as $type) {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return list<array{types?: list<string>}>
     */
    private function declaredFilters(Manifest $manifest): array
    {
        $declared = $manifest->contributes()['listFilters'] ?? [];

        return is_array($declared) ? array_values(array_filter($declared, is_array(...))) : [];
    }
}
