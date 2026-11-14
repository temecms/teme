<?php

declare(strict_types=1);

namespace App\Domain\Fields\Types;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardReference;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldType;
use App\Domain\Fields\Rules\TermReference;
use App\Domain\Site\ListingViews;
use App\Domain\Site\ViewModels\TermLink;
use Illuminate\Support\Facades\DB;

/**
 * A reference to a term, stored as its ID, never its name or slug, so it
 * survives a rename (rule 9); only a term of a hierarchical taxonomy, as a
 * primary category is (I-295).
 */
final class TermField implements FieldType
{
    public function name(): string
    {
        return 'term';
    }

    public function rules(Field $field): array
    {
        return ['nullable', new TermReference];
    }

    public function default(Field $field): mixed
    {
        return null;
    }

    public function normalise(Field $field, mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /** The term's name; the page records its tag, so it goes when the term is renamed. */
    public function structuredData(Field $field, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::term($value));
        $name = DB::table('terms')->where('id', $value)->value('name');

        return is_string($name) ? $name : null;
    }

    /** No control: only core's own panels draw a term. */
    public function edit(Field $field): array
    {
        return ['component' => null, 'settings' => []];
    }

    /** The term's link now, or null when nothing is stored or the term is gone. */
    public function render(Field $field, mixed $value): ?TermLink
    {
        return is_string($value) && $value !== '' ? (app(ListingViews::class)->termLinks([$value])[$value] ?? null) : null;
    }

    /** Not sortable. */
    public function sqlType(Field $field): ?string
    {
        return null;
    }

    /** The term, by ID. */
    public function references(Field $field, mixed $value): array
    {
        return is_string($value) && $value !== '' ? [new CardReference('term', $value)] : [];
    }

    /** Nothing: a term adds no words to search. */
    public function text(Field $field, mixed $value): string
    {
        return '';
    }
}
