<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Writes one item straight to the table and answers its ID, so the test
 * pins the database, not the code.
 */
function lschItem(string $slug, ?string $language = null, ?string $group = null, bool $trashed = false, string $type = 'post'): string
{
    $id = (string) Str::ulid();

    DB::table('contents')->insert([
        'id' => $id, 'type' => $type, 'slug' => $slug, 'status' => 'draft', 'fields' => '{}',
        'language' => $language, 'translation_group' => $group,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $trashed ? now() : null,
    ]);

    return $id;
}

function lschTerm(string $slug, ?string $language = null, ?string $group = null): string
{
    $id = (string) Str::ulid();

    DB::table('terms')->insert([
        'id' => $id, 'taxonomy' => 'category', 'name' => $slug, 'slug' => $slug, 'fields' => '{}',
        'language' => $language, 'translation_group' => $group, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * The SQLSTATE the write raises, or null when it succeeds. Run in a
 * savepoint, so a refused write leaves the test's transaction usable.
 */
function lschState(Closure $write): ?string
{
    try {
        DB::transaction(fn () => $write());
    } catch (QueryException $exception) {
        return (string) $exception->getCode();
    }

    return null;
}

function lschGroup(): string
{
    return strtolower((string) Str::ulid());
}

it('adds a nullable language and translation group to contents and terms, a source revision to revisions and translations to settings', function (): void {
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where(fn ($query) => $query
            ->where(fn ($query) => $query->whereIn('table_name', ['contents', 'terms'])->whereIn('column_name', ['language', 'translation_group']))
            ->orWhere(fn ($query) => $query->where('table_name', 'content_revisions')->where('column_name', 'source_revision_id'))
            ->orWhere(fn ($query) => $query->where('table_name', 'settings')->where('column_name', 'translations')))
        ->get(['table_name', 'column_name', 'is_nullable', 'column_default']);

    expect($columns->map(fn (object $column): string => $column->table_name.'.'.$column->column_name)->all())->toEqualCanonicalizing([
        'contents.language', 'contents.translation_group', 'terms.language', 'terms.translation_group',
        'content_revisions.source_revision_id', 'settings.translations',
    ])
        ->and($columns->pluck('is_nullable')->unique()->all())->toBe(['YES'])
        ->and($columns->pluck('column_default')->filter()->all())->toBe([]);
});

it('lets two items of a type share a slug in different languages, never in the same one', function (): void {
    expect(lschState(fn () => lschItem('about')))->toBeNull()
        ->and(lschState(fn () => lschItem('about', 'fr')))->toBeNull()
        ->and(lschState(fn () => lschItem('about')))->toBe('23505')
        ->and(lschState(fn () => lschItem('about', 'fr')))->toBe('23505');
});

it('frees a slug in the trash, in every language', function (): void {
    lschItem('about', 'fr', trashed: true);
    lschItem('about', trashed: true);

    expect(lschState(fn () => lschItem('about', 'fr')))->toBeNull()
        ->and(lschState(fn () => lschItem('about')))->toBeNull();
});

it('keeps one live item per language in a translation group', function (): void {
    $group = lschGroup();
    lschItem('one', null, $group);

    expect(lschState(fn () => lschItem('two', null, $group)))->toBe('23505')
        ->and(lschState(fn () => lschItem('un', 'fr', $group)))->toBeNull()
        ->and(lschState(fn () => lschItem('deux', 'fr', $group, trashed: true)))->toBeNull();
});

it('lets two terms of a taxonomy share a slug in different languages, never in the same one', function (): void {
    expect(lschState(fn () => lschTerm('news')))->toBeNull()
        ->and(lschState(fn () => lschTerm('news', 'fr')))->toBeNull()
        ->and(lschState(fn () => lschTerm('news')))->toBe('23505')
        ->and(lschState(fn () => lschTerm('news', 'fr')))->toBe('23505');
});

it('keeps one term per language in a translation group', function (): void {
    $group = lschGroup();
    lschTerm('news', null, $group);

    expect(lschState(fn () => lschTerm('more-news', null, $group)))->toBe('23505')
        ->and(lschState(fn () => lschTerm('actualites', 'fr', $group)))->toBeNull()
        ->and(lschState(fn () => lschTerm('nouvelles', 'fr', $group)))->toBe('23505');
});

it('refuses a language that is not a canonical tag, and a group that is not a lower-case ULID', function (): void {
    foreach (['FR', 'fr-fr', 'en_GB', 'english'] as $index => $tag) {
        expect(lschState(fn () => lschItem("bad-{$index}", $tag)))->toBe('23514')
            ->and(lschState(fn () => lschTerm("bad-{$index}", $tag)))->toBe('23514');
    }

    expect(lschState(fn () => lschItem('brazil', 'pt-BR')))->toBeNull()
        ->and(lschState(fn () => lschItem('pidgin', 'pcm')))->toBeNull()
        ->and(lschState(fn () => lschItem('upper', null, (string) Str::ulid())))->toBe('23514')
        ->and(lschState(fn () => lschTerm('upper', null, (string) Str::ulid())))->toBe('23514')
        ->and(lschState(fn () => lschItem('lower', null, lschGroup())))->toBeNull();
});

it('forgets the revision a translation came from when that revision is deleted', function (): void {
    $source = lschItem('about');
    $translation = lschItem('a-propos', 'fr');
    $sourceRevision = (string) Str::ulid();
    $translationRevision = (string) Str::ulid();
    DB::table('content_revisions')->insert(['id' => $sourceRevision, 'content_id' => $source, 'document' => '{}', 'title' => 'About', 'created_at' => now()]);
    DB::table('content_revisions')->insert(['id' => $translationRevision, 'content_id' => $translation, 'document' => '{}', 'title' => 'À propos', 'source_revision_id' => $sourceRevision, 'created_at' => now()]);

    DB::table('content_revisions')->where('id', $sourceRevision)->delete();

    expect(DB::table('content_revisions')->where('id', $translationRevision)->value('source_revision_id'))->toBeNull();
});

it('keeps a settings row\'s translations an object of at most 64 KB', function (): void {
    $write = fn (?string $translations) => fn () => DB::table('settings')->updateOrInsert(['group_key' => 'general'], ['overrides' => '{}', 'translations' => $translations]);

    expect(lschState($write('["fr"]')))->toBe('23514')
        ->and(lschState($write(json_encode(['fr' => ['tagline' => str_repeat('x', 70_000)]]))))->toBe('23514')
        ->and(lschState($write('{}')))->toBeNull()
        ->and(lschState($write(null)))->toBeNull();
});

it('leaves every index this release builds valid', function (): void {
    $names = [
        'contents_type_slug_language_live_unique', 'contents_translation_group_language_unique',
        'terms_taxonomy_slug_language_unique', 'terms_translation_group_language_unique',
        'content_revisions_source_revision', 'contents_other_language_published',
        'contents_translation_group', 'contents_language_type',
    ];

    $valid = DB::table('pg_index')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
        ->whereIn('pg_class.relname', $names)
        ->pluck('pg_index.indisvalid', 'pg_class.relname')
        ->all();

    expect(array_keys($valid))->toEqualCanonicalizing($names)
        ->and(array_unique(array_values($valid)))->toBe([true]);
});

it('no longer has the one-language slug index or the term slug key', function (): void {
    expect(DB::table('pg_class')->where('relname', 'contents_type_slug_live_unique')->exists())->toBeFalse()
        ->and(DB::table('pg_constraint')->where('conname', 'terms_taxonomy_slug_unique')->exists())->toBeFalse();
});

it('can answer the previous release\'s slug search from the new index', function (): void {
    foreach (range(1, 150) as $number) {
        lschItem("filler-{$number}");
        lschItem("filler-{$number}", type: 'page');
    }

    lschItem('about');
    lschItem('about-2');

    DB::statement('ANALYZE contents');

    $plan = DB::transaction(function (): string {
        DB::statement('SET LOCAL enable_seqscan = off');

        // Slugs::unique() as main writes it before this release: no language.
        $rows = DB::select(
            'EXPLAIN SELECT max(case when slug = ? then 1 else 0 end) AS base_taken
             FROM contents WHERE type = ? AND deleted_at IS NULL AND (slug = ? OR slug LIKE ?) AND slug ~ ?',
            ['about', 'post', 'about', 'about-%', '^about(-[0-9]+)?$'],
        );

        return implode("\n", array_map(fn (object $row): string => (string) $row->{'QUERY PLAN'}, $rows));
    });

    expect($plan)->toContain('contents_type_slug_language_live_unique')
        ->and($plan)->not->toContain('Seq Scan');
});
