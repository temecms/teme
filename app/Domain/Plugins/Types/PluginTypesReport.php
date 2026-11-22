<?php

/**
 * Says in words what applying a plugin's types file changed on the site,
 * from the report its row keeps (docs/architecture/plugins.md, *Content
 * types from a plugin*): what it took over, updated, added and gave back.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Types;

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Arr;

final class PluginTypesReport
{
    /**
     * The sentences for $report, joined; empty when it changed nothing a
     * person needs told. Types and taxonomies are named by their plural
     * name and fields only counted, so a plugin with dozens of fields still
     * gets a notice that fits a toast.
     *
     * @param  array<string, mixed>  $report
     */
    public static function sentence(array $report): string
    {
        $taxonomies = is_array($report['taxonomies'] ?? null) ? $report['taxonomies'] : [];
        $adopted = self::listed($report, 'adopted');
        $released = self::listed($report, 'released');
        $added = self::things([...self::names($report['added'] ?? []), ...self::names($taxonomies['added'] ?? [])], self::names($report['fieldsAdded'] ?? []));
        $took = self::things($adopted['kinds'], $adopted['fields']);
        $changed = self::things(
            array_values(array_diff([...self::names($report['updated'] ?? []), ...self::names($taxonomies['updated'] ?? [])], $adopted['kinds'])),
            [],
        );
        $fields = self::names($report['fieldsUpdated'] ?? []);
        $sentences = [];

        if ($added !== []) {
            $sentences[] = __('Added :names.', ['names' => self::join($added)]);
        }

        if ($took !== []) {
            $sentences[] = __('Took over :names, keeping their items.', ['names' => self::join($took)]);
        }

        if ($changed !== []) {
            $sentences[] = __('Changed :names to match its file.', ['names' => self::join($changed)]);
        }

        if ($fields !== [] && $added === [] && $took === [] && $changed === []) {
            $sentences[] = __('Changed :fields.', ['fields' => self::count($fields)]);
        }

        $gone = self::things($released['kinds'], $released['fields']);

        if ($gone !== []) {
            $sentences[] = __('Its file no longer has :names, so they stay as this site’s own, with their items.', ['names' => self::join($gone)]);
        }

        return implode(' ', $sentences);
    }

    /**
     * The names a sentence lists: each type or taxonomy by its plural name, then how many fields.
     *
     * @param  list<string>  $kinds
     * @param  list<string>  $fields
     *
     * @return list<string>
     */
    private static function things(array $kinds, array $fields): array
    {
        $names = array_map(self::plural(...), $kinds);

        return $fields === [] ? $names : [...$names, self::count($fields)];
    }

    /**
     * "1 field" or "40 fields".
     *
     * @param  list<string>  $fields
     */
    private static function count(array $fields): string
    {
        return trans_choice(':n field|:n fields', count($fields), ['n' => (string) count($fields)]);
    }

    /**
     * A type's or taxonomy's plural name, or its key when neither is registered.
     */
    private static function plural(string $key): string
    {
        if (app(ContentTypeRegistry::class)->has($key)) {
            return app(ContentTypeRegistry::class)->get($key)->plural;
        }

        return app(TaxonomyRegistry::class)->has($key) ? app(TaxonomyRegistry::class)->get($key)->plural : $key;
    }

    /**
     * @param  array<string, mixed>  $report
     *
     * @return array{kinds: list<string>, fields: list<string>}
     */
    private static function listed(array $report, string $key): array
    {
        $kinds = is_array($report[$key] ?? null) ? $report[$key] : [];

        return ['kinds' => [...self::names($kinds['types'] ?? []), ...self::names($kinds['taxonomies'] ?? [])], 'fields' => self::names($kinds['fields'] ?? [])];
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }

    /**
     * @param  list<string>  $names
     */
    private static function join(array $names): string
    {
        return Arr::join($names, ', ', ' '.__('and').' ');
    }
}
