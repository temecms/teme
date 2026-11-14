<?php

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Cards\CardReference;
use App\Domain\Cards\LinkResolver;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;
use App\Domain\StructuredData\GraphAddresses;
use Illuminate\Validation\Rule;

/**
 * A reference to another content item, stored as its ID — never its URL or
 * slug, so the link survives a move or a rename (rule 9).
 */
final class EntryField implements FieldType
{
    public function name(): string
    {
        return 'entry';
    }

    public function rules(Field $field): array
    {
        return ['nullable', 'string', Rule::exists('contents', 'id')->whereNull('deleted_at')];
    }

    public function default(Field $field): mixed
    {
        return null;
    }

    public function normalise(Field $field, mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /** The target's live address, made now; the resolver records its tag. */
    public function structuredData(Field $field, mixed $value): ?string
    {
        $path = is_string($value) && $value !== '' ? app(LinkResolver::class)->url($value) : null;

        return $path === null ? null : GraphAddresses::absolute($path);
    }

    /** The control; an item needs no settings. */
    public function edit(Field $field): array
    {
        return ['component' => 'entry', 'settings' => []];
    }

    /** The target's address now, or null when it has none (unpublished, trashed or gone). */
    public function render(Field $field, mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? app(LinkResolver::class)->url($value) : null;
    }

    /** Not sortable. */
    public function sqlType(Field $field): ?string
    {
        return null;
    }

    /** The target item, by ID. */
    public function references(Field $field, mixed $value): array
    {
        return is_string($value) && $value !== '' ? [CardReference::content($value)] : [];
    }

    /** Nothing: a link adds no words to search. */
    public function text(Field $field, mixed $value): string
    {
        return '';
    }
}
