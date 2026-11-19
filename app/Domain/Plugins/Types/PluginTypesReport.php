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

use Illuminate\Support\Arr;

final class PluginTypesReport
{
    /**
     * The sentences for $report, joined; empty when it changed nothing a
     * person needs told. Types and taxonomies are named by key, fields as
     * type.handle.
     *
     * @param  array<string, mixed>  $report
     */
    public static function sentence(array $report): string
    {
        $adopted = self::listed($report, 'adopted');
        $released = self::listed($report, 'released');
        $taxonomies = is_array($report['taxonomies'] ?? null) ? $report['taxonomies'] : [];
        $added = [...self::names($report['added'] ?? []), ...self::names($taxonomies['added'] ?? []), ...self::names($report['fieldsAdded'] ?? [])];
        $updated = [...self::names($report['updated'] ?? []), ...self::names($taxonomies['updated'] ?? [])];
        $fields = self::names($report['fieldsUpdated'] ?? []);
        $sentences = [];

        if ($added !== []) {
            $sentences[] = __('It added :names.', ['names' => self::join($added)]);
        }

        if ($adopted !== []) {
            $sentences[] = __('It took over :names, already on this site, keeping their items.', ['names' => self::join($adopted)]);
        }

        $changed = array_values(array_diff($updated, $adopted));

        if ($changed !== []) {
            $sentences[] = __('It changed :names to match its file.', ['names' => self::join($changed)]);
        }

        if ($fields !== [] && $added === [] && $adopted === [] && $changed === []) {
            $sentences[] = __('It changed the fields :names.', ['names' => self::join($fields)]);
        }

        if ($released !== []) {
            $sentences[] = __('Its file no longer has :names, so they stay as this site’s own, with their items.', ['names' => self::join($released)]);
        }

        return implode(' ', $sentences);
    }

    /**
     * @param  array<string, mixed>  $report
     *
     * @return list<string>
     */
    private static function listed(array $report, string $key): array
    {
        $kinds = is_array($report[$key] ?? null) ? $report[$key] : [];

        return [...self::names($kinds['types'] ?? []), ...self::names($kinds['taxonomies'] ?? []), ...self::names($kinds['fields'] ?? [])];
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
