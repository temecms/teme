<?php

/**
 * A web address, http or https. Stored trimmed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;

final class UrlField implements FieldType
{
    public function name(): string
    {
        return 'url';
    }

    public function rules(Field $field): array
    {
        return ['nullable', 'url:http,https', 'max:2048'];
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

    /** The control, with the longest address it takes. */
    public function edit(Field $field): array
    {
        return ['component' => 'url', 'settings' => ['maxLength' => 2048]];
    }

    /** The address, or the empty string when nothing is stored. */
    public function render(Field $field, mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Not sortable. */
    public function sqlType(Field $field): ?string
    {
        return null;
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
