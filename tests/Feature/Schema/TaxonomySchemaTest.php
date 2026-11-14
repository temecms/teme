<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function taxonomyInsertTerm(string $taxonomy, string $slug, ?string $parentId = null): string
{
    $id = (string) Str::ulid();

    DB::table('terms')->insert([
        'id' => $id,
        'taxonomy' => $taxonomy,
        'name' => $slug,
        'slug' => $slug,
        'parent_id' => $parentId,
        'fields' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('keeps slugs unique within a taxonomy, not across them', function (): void {
    taxonomyInsertTerm('category', 'news');
    taxonomyInsertTerm('tag', 'news');

    expect(fn () => taxonomyInsertTerm('category', 'news'))->toThrow(QueryException::class);
});

// Since 1.5·0 the taxonomy registry enforces these two rules, not a CHECK:
// the table accepts the row, and the Operation refuses it.
it('allows a parent only on categories', function (): void {
    $parent = taxonomyInsertTerm('category', 'parent');

    taxonomyInsertTerm('category', 'child', $parent);

    expect(fn () => taxonomyInsertTerm('tag', 'tagged-child', $parent))->not->toThrow(QueryException::class)
        ->and(fn () => typesTerm(typesEditor(), 'tag', 'Another child', $parent))->toThrow(ValidationException::class);
});

it('refuses an unknown taxonomy', function (): void {
    expect(fn () => taxonomyInsertTerm('genre', 'jazz'))->not->toThrow(QueryException::class)
        ->and(fn () => typesTerm(typesEditor(), 'genre', 'Blues'))->toThrow(ValidationException::class);
});

it('adds the assignment tables and a terms list to revisions', function (): void {
    expect(Schema::hasColumns('content_terms', ['content_id', 'term_id', 'scope', 'position']))->toBeTrue()
        ->and(Schema::hasColumn('content_revisions', 'terms'))->toBeTrue();
});
