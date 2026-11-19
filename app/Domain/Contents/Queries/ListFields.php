<?php

/**
 * A type's own fields as its list's columns: those drawn in Details whose
 * values fit on one line, and each value as the words a cell shows.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldPlace;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\SelectField;

final class ListFields
{
    /** The field types whose values a list can show in a cell. */
    private const array KINDS = ['text', 'number', 'select', 'boolean', 'datetime', 'url'];

    /**
     * The type's fields a list may show as columns, in the editor's order.
     *
     * @since next
     *
     * @return list<Field>
     */
    public static function of(string $type): array
    {
        return array_values(array_filter(
            app(FieldRegistry::class)->forType($type),
            fn (Field $field): bool => $field->place === FieldPlace::Details && in_array($field->type->name(), self::KINDS, true),
        ));
    }

    /**
     * Each column's key, label and kind, for the list page.
     *
     * @since next
     *
     * @return list<array{key: string, label: string, kind: string}>
     */
    public static function columns(string $type): array
    {
        return array_map(fn (Field $field): array => [
            'key' => $field->key,
            'label' => $field->label,
            'kind' => $field->type->name(),
        ], self::of($type));
    }

    /**
     * One item's values for these fields, as a cell shows them, leaving out
     * each one that is empty.
     *
     * @since next
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values  the revision's fields, by key
     *
     * @return array<string, string>
     */
    public static function cells(array $fields, array $values): array
    {
        $cells = [];

        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $text = match (true) {
                $field->type instanceof SelectField => $field->type->text($field, $value),
                is_bool($value) => $value ? __('Yes') : __('No'),
                is_scalar($value) => (string) $value,
                default => '',
            };

            if ($text !== '') {
                $cells[$field->key] = $text;
            }
        }

        return $cells;
    }
}
