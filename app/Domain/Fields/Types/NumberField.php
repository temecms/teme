<?php

/**
 * A number, whole or decimal. An empty value is stored as null.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;

final class NumberField implements FieldType
{
    public function name(): string
    {
        return 'number';
    }

    public function rules(Field $field): array
    {
        return ['nullable', 'numeric'];
    }

    public function default(Field $field): mixed
    {
        return null;
    }

    public function normalise(Field $field, mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value + 0;
    }

    public function structuredData(Field $field, mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : null);
    }

    /** The control; a number needs no settings. */
    public function edit(Field $field): array
    {
        return ['component' => 'number', 'settings' => []];
    }

    /** The number, or null when nothing is stored. */
    public function render(Field $field, mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : null);
    }

    /** Numeric. */
    public function sqlType(Field $field): string
    {
        return 'numeric';
    }

    /** Nothing: this value points at nothing. */
    public function references(Field $field, mixed $value): array
    {
        return [];
    }

    /** Nothing: this value adds no words to search. */
    public function text(Field $field, mixed $value): string
    {
        return '';
    }
}
