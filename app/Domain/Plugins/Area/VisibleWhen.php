<?php

/**
 * The one show/hide rule of a plugin's forms and settings pages
 * (docs/plans/plugin-sockets-2.md, A2): every key must match, a list matches
 * any of its values, and a hidden field counts as empty for the fields after
 * it. `resources/js/lib/visible-when.ts` mirrors it line for line.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

final class VisibleWhen
{
    /**
     * Whether every condition holds against these values.
     *
     * @param  array<array-key, mixed>  $when  key => value, or key => [value, value]
     * @param  array<array-key, mixed>  $values
     */
    public static function shows(array $when, array $values): bool
    {
        foreach ($when as $key => $wanted) {
            $have = self::flat($values[$key] ?? null);
            $want = self::flat($wanted);

            if (array_intersect($want, $have) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The values of the fields that show, in declared order, each hidden
     * field left out; a hidden field counts as empty for the fields after it.
     *
     * @param  list<array<array-key, mixed>>  $fields  each with its `key` and, maybe, its `visibleWhen`
     * @param  array<array-key, mixed>  $values
     *
     * @return array<string, mixed>
     */
    public static function visibleValues(array $fields, array $values): array
    {
        $kept = [];

        foreach ($fields as $field) {
            $key = is_string($field['key'] ?? null) ? $field['key'] : null;
            $when = is_array($field['visibleWhen'] ?? null) ? $field['visibleWhen'] : [];

            if ($key !== null && self::shows($when, $kept)) {
                $kept[$key] = $values[$key] ?? null;
            }
        }

        return $kept;
    }

    /**
     * The keys of the fields that are hidden, given these values.
     *
     * @param  list<array<array-key, mixed>>  $fields
     * @param  array<array-key, mixed>  $values
     *
     * @return list<string>
     */
    public static function hiddenKeys(array $fields, array $values): array
    {
        $shown = self::visibleValues($fields, $values);
        $hidden = [];

        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null) && ! array_key_exists($field['key'], $shown)) {
                $hidden[] = $field['key'];
            }
        }

        return $hidden;
    }

    /**
     * @return list<string>
     */
    private static function flat(mixed $value): array
    {
        $list = is_array($value) ? array_values($value) : [$value];

        return array_values(array_map(fn (mixed $one): string => match (true) {
            is_bool($one) => $one ? 'true' : 'false',
            $one === null => '',
            default => (string) $one,
        }, array_filter($list, fn (mixed $one): bool => is_scalar($one) || $one === null)));
    }
}
