<?php

declare(strict_types=1);

use App\Domain\Status\Checks\StoredTaxonomiesRefused;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Providers\TypesServiceProvider;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('registers a stored taxonomy after code’s, and leaves code’s alone', function (): void {
    txStoreRow(txDefinition());
    app(ContentTypeRegistry::class)->refreshStored();
    $taxonomies = app(TaxonomyRegistry::class);

    expect($taxonomies->keys())->toBe(['category', 'tag', 'genre'])
        ->and($taxonomies->get('genre'))->toEqual(new Taxonomy('genre', 'Genre', 'Genres', 'genres', hierarchical: true, icon: 'folder', base: 'genre', stored: true))
        ->and($taxonomies->bySegment('genres')?->key)->toBe('genre')
        ->and(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, $taxonomies->fromCode()))->toBe(['category', 'tag']);
});

it('gives a type code declares the stored taxonomies that name it, after its own, and fromCode() keeps code’s', function (): void {
    txStoreRow(txDefinition(['code_types' => ['post']]));
    app(ContentTypeRegistry::class)->refreshStored();
    $types = app(ContentTypeRegistry::class);

    expect($types->get('post')->taxonomies)->toBe(['category', 'tag', 'genre'])
        ->and($types->fromCode()[0]->taxonomies)->toBe(['category', 'tag'])
        ->and(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->forType('post')))->toBe(['category', 'tag', 'genre'])
        ->and(array_map(fn ($type): string => $type->key, app(TaxonomyRegistry::class)->typesUsing('genre')))->toBe(['post']);
});

it('registers a stored type that names a stored taxonomy', function (): void {
    txStoreRow(txDefinition());
    ctStoreRow(ctDefinition(['taxonomies' => ['genre']]));
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['genre'])
        ->and(app(ContentTypeRegistry::class)->refused())->toBe([]);
});

it('reads both tables in one look when the cache store has no copy, and none when it has', function (): void {
    txStoreRow(txDefinition());
    $taxonomies = app(TaxonomyRegistry::class);
    app(ContentTypeRegistry::class)->refreshStored();
    $reads = fn (Closure $run): array => array_values(array_filter(dashQueries($run), fn (string $sql): bool => str_contains($sql, 'to_regclass') || str_contains($sql, 'taxonomy_definitions')));

    expect($reads(fn () => [$taxonomies->all(), $taxonomies->has('genre')]))->toBe([]);

    Cache::forget(StoredTypes::CACHE_KEY);
    app(ContentTypeRegistry::class)->refreshStored();

    expect($reads(fn () => [$taxonomies->all(), $taxonomies->get('genre'), $taxonomies->segments()]))->toHaveCount(2);
});

it('reads a copy the cache store kept from before taxonomies were stored as none', function (): void {
    Cache::put(StoredTypes::CACHE_KEY, ['version' => 'before', 'definitions' => []], 60);
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(TaxonomyRegistry::class)->keys())->toBe(['category', 'tag']);
});

it('sees another process’s new taxonomy at the next request, not before', function (): void {
    $taxonomies = app(TaxonomyRegistry::class);
    expect($taxonomies->has('genre'))->toBeFalse();

    txStoreRow(txDefinition());
    expect($taxonomies->has('genre'))->toBeFalse();

    event(new RequestHandled(request(), response('')));
    expect($taxonomies->has('genre'))->toBeTrue();
});

it('changes the taxonomies’ stamp when a taxonomy is stored, and only then', function (): void {
    $taxonomies = app(TaxonomyRegistry::class);
    $before = $taxonomies->stamp();
    app(ContentTypeRegistry::class)->refreshStored();
    expect($taxonomies->stamp())->toBe($before);

    txStoreRow(txDefinition());
    app(ContentTypeRegistry::class)->refreshStored();
    expect($taxonomies->stamp())->not->toBe($before);
});

it('leaves out a stored taxonomy that clashes or names what isn’t there, keeps why, and a type naming it with it', function (array $overrides, string $reason): void {
    txStoreRow(txDefinition($overrides));
    ctStoreRow(ctDefinition(['taxonomies' => [$overrides['key'] ?? 'genre']]));
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(TaxonomyRegistry::class)->refused())->toBe([$overrides['key'] ?? 'genre' => $reason])
        ->and(app(ContentTypeRegistry::class)->has('film'))->toBeFalse();
})->with([
    'a code type’s segment' => [['segment' => 'posts'], 'Taxonomy [genre] cannot use segment [posts]: a content type has it.'],
    'a stored type’s segment' => [['segment' => 'films'], 'Taxonomy [genre] cannot use segment [films]: a content type has it.'],
    'code’s taxonomy’s segment' => [['segment' => 'tags'], 'Taxonomy [genre] cannot use segment [tags]: another taxonomy has it.'],
    'a type with no list' => [['code_types' => ['region']], 'Taxonomy [genre] is used by [region], which isn’t a listed type code declares.'],
    'a type nobody declares' => [['code_types' => ['film']], 'Taxonomy [genre] is used by [film], which isn’t a listed type code declares.'],
]);

it('names a saved taxonomy that can’t be used on the Status banner', function (): void {
    txStoreRow(txDefinition(['segment' => 'posts']));
    app(ContentTypeRegistry::class)->refreshStored();

    $banner = app(StoredTaxonomiesRefused::class)->banner();

    expect($banner?->message)->toBe('The saved taxonomy “genre” can’t be used: Taxonomy [genre] cannot use segment [posts]: a content type has it.')
        ->and(app(StoredTaxonomiesRefused::class)->ability())->toBe('types.manage');
});

it('has no stored taxonomies before their table exists', function (): void {
    Schema::drop(StoredTaxonomies::TABLE);
    Cache::forget(StoredTypes::CACHE_KEY);
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(TaxonomyRegistry::class)->keys())->toBe(['category', 'tag']);
});

it('remembers the types without taxonomies before their table exists, and nothing before the types’ table does', function (): void {
    Schema::drop(StoredTaxonomies::TABLE);
    app(StoredTypes::class)->remember();
    expect(Cache::get(StoredTypes::CACHE_KEY)['taxonomies'])->toBe([]);

    Schema::drop('content_type_definitions');
    Cache::forget(StoredTypes::CACHE_KEY);
    app(StoredTypes::class)->remember();
    expect(Cache::has(StoredTypes::CACHE_KEY))->toBeFalse();
});

it('asks nothing of the database for what boot checks, taxonomies included', function (): void {
    $statements = dashQueries(function (): void {
        typesFresh();
        app(TaxonomyRegistry::class)->fromCode();
        app(TaxonomyRegistry::class)->isRegistered('category');
        app(TaxonomyRegistry::class)->registeredSegments();
        app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics'));
        app(TypesServiceProvider::class, ['app' => app()])->verify();
    });

    expect($statements)->toBe([]);
});
