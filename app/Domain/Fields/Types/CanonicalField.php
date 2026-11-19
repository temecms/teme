<?php

/**
 * Where search engines should look for an item's content (I-292): nothing for
 * the item itself, another item by its ID, or an address on another site;
 * never this site's own address, which is its item (rule 9).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Cards\CardReference;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;
use App\Domain\Fields\Rules\CanonicalChoice;

final class CanonicalField implements FieldType
{
    public function name(): string
    {
        return 'canonical';
    }

    public function rules(Field $field): array
    {
        return ['nullable', new CanonicalChoice];
    }

    public function default(Field $field): mixed
    {
        return null;
    }

    /**
     * @return array{item: string}|array{address: string}|null
     */
    public function normalise(Field $field, mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : ['address' => $value];
        }

        if (! is_array($value)) {
            return null;
        }

        if (is_string($value['item'] ?? null) && $value['item'] !== '') {
            return ['item' => $value['item']];
        }

        if (is_string($value['address'] ?? null) && trim($value['address']) !== '') {
            return ['address' => trim($value['address'])];
        }

        return null;
    }

    /** Never a value: a canonical is where search engines are sent, not a fact about the item. */
    public function structuredData(Field $field, mixed $value): mixed
    {
        return null;
    }

    /** No control: only core's own panels draw a canonical address. */
    public function edit(Field $field): array
    {
        return ['component' => null, 'settings' => []];
    }

    /** Never a value: the head draws it, not a template. */
    public function render(Field $field, mixed $value): mixed
    {
        return null;
    }

    /** Not sortable. */
    public function sqlType(Field $field): ?string
    {
        return null;
    }

    /** The other item when the canonical names one by ID; an address on another site points at nothing here. */
    public function references(Field $field, mixed $value): array
    {
        $item = is_array($value) ? ($value['item'] ?? null) : null;

        return is_string($item) && $item !== '' ? [CardReference::content($item)] : [];
    }

    /** Nothing: a canonical adds no words to search. */
    public function text(Field $field, mixed $value): string
    {
        return '';
    }
}
