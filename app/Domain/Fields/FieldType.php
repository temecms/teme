<?php

declare(strict_types=1);

namespace App\Domain\Fields;

use App\Domain\Cards\CardReference;

/**
 * One kind of field value, text, a number, an image, and everything the
 * system asks of it: how it is checked, defaulted and stored, how the editor
 * draws it, how a template and structured data read it, what it points at,
 * the words it adds to search and how an index sorts it. Internal until the
 * plugin API; docs/studies/content-and-fields.md §5.1.
 */
interface FieldType
{
    /**
     * The type's name as the reference documentation shows it.
     */
    public function name(): string;

    /**
     * Laravel validation rules for one value of this field.
     *
     * @return list<mixed>
     */
    public function rules(Field $field): array;

    /**
     * The value a field has when nothing is stored for it.
     */
    public function default(Field $field): mixed;

    /**
     * The value as it is stored, from a value that has passed rules().
     */
    public function normalise(Field $field, mixed $value): mixed;

    /**
     * The value as structured data states it (I-709): plain text, a number,
     * true or false, an address, a date with the site's offset, an
     * ImageObject; null when it says nothing, or nothing a node can carry.
     */
    public function structuredData(Field $field, mixed $value): mixed;

    /**
     * How the editor draws this field: the name of the browser's control for
     * it, or null when only core's own panels draw it, and the settings that
     * control needs, JSON-safe, options as an ordered list.
     *
     * @return array{component: string|null, settings: array<string, mixed>}
     */
    public function edit(Field $field): array;

    /**
     * The stored value as a template draws it, targets resolved now from
     * their IDs (rule 9); callers pass default() when nothing is stored.
     */
    public function render(Field $field, mixed $value): mixed;

    /**
     * The PostgreSQL type an index on this field casts its text to (text,
     * numeric or boolean), or null when it can't be filtered or sorted.
     */
    public function sqlType(Field $field): ?string;

    /**
     * What the stored value points at by ID, in order, each once.
     *
     * @return list<CardReference>
     */
    public function references(Field $field, mixed $value): array;

    /**
     * The plain words the value adds to the item's search text, or ''.
     */
    public function text(Field $field, mixed $value): string;
}
