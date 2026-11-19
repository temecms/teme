<?php

/**
 * Several lines of plain text, such as a meta description or an excerpt.
 * Stored trimmed; the field's maxLength caps it, 5,000 by default.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;

final class TextareaField implements FieldType
{
    public function name(): string
    {
        return 'textarea';
    }

    public function rules(Field $field): array
    {
        return ['nullable', 'string', 'max:'.($field->maxLength ?? 5000)];
    }

    public function default(Field $field): mixed
    {
        return '';
    }

    public function normalise(Field $field, mixed $value): mixed
    {
        return trim((string) $value);
    }

    /** The text, trimmed. */
    public function structuredData(Field $field, mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** The control, with the longest value it takes. */
    public function edit(Field $field): array
    {
        return ['component' => 'textarea', 'settings' => ['maxLength' => $field->maxLength ?? 5000]];
    }

    /** The stored text, or the empty string when nothing is stored. */
    public function render(Field $field, mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Text. */
    public function sqlType(Field $field): ?string
    {
        return null;
    }

    /** Nothing: text points at nothing. */
    public function references(Field $field, mixed $value): array
    {
        return [];
    }

    /** The value, trimmed. */
    public function text(Field $field, mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
