<?php

/**
 * A yes-or-no switch. Anything PHP reads as true is stored as true.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;

final class BooleanField implements FieldType
{
    public function name(): string
    {
        return 'boolean';
    }

    public function rules(Field $field): array
    {
        return ['nullable', 'boolean'];
    }

    public function default(Field $field): mixed
    {
        return false;
    }

    public function normalise(Field $field, mixed $value): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function structuredData(Field $field, mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** The control; a switch needs no settings. */
    public function edit(Field $field): array
    {
        return ['component' => 'boolean', 'settings' => []];
    }

    /** True or false. */
    public function render(Field $field, mixed $value): bool
    {
        return $value === true;
    }

    /** Boolean. */
    public function sqlType(Field $field): string
    {
        return 'boolean';
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
