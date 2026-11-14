<?php

declare(strict_types=1);

use App\Domain\Status\Checks\StoredTypesRefused;
use App\Domain\StructuredData\TypeSchemas;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Providers\TypesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('registers the stored types after code’s, each listed, and leaves code’s alone', function (): void {
    ctStoreRow(ctDefinition());
    $types = app(ContentTypeRegistry::class);
    $types->refreshStored();

    expect($types->keys())->toBe(['post', 'page', 'region', 'film'])
        ->and($types->get('film')->stored)->toBeTrue()
        ->and($types->get('film')->listed)->toBeTrue()
        ->and($types->get('film')->pattern)->toBe('/films/{slug}/')
        ->and($types->bySegment('films')?->key)->toBe('film')
        ->and($types->segments())->toBe(['posts', 'pages', 'films'])
        ->and(array_map(fn (ContentType $type): string => $type->key, $types->fromCode()))->toBe(['post', 'page', 'region']);
});

it('reads the stored types from the cache store’s copy, and from the table only when there is none', function (): void {
    ctStoreRow(ctDefinition());
    $types = app(ContentTypeRegistry::class);
    $types->refreshStored();
    $reads = fn (Closure $run): array => array_values(array_filter(
        dashQueries($run),
        fn (string $sql): bool => str_contains($sql, 'content_type_definitions') || str_contains($sql, 'to_regclass'),
    ));

    expect($reads(function () use ($types): void {
        $types->all();
        $types->has('film');
        $types->keys();
    }))->toBe([]);

    Cache::forget(StoredTypes::CACHE_KEY);
    $types->refreshStored();

    expect($reads(function () use ($types): void {
        $types->all();
        $types->get('film');
        $types->segments();
    }))->toHaveCount(2)
        ->and(Cache::has(StoredTypes::CACHE_KEY))->toBeTrue();
});

it('sees another process’s change at a worker’s next job, not before', function (): void {
    $types = app(ContentTypeRegistry::class);
    expect($types->has('film'))->toBeFalse();

    ctStoreRow(ctDefinition());

    expect($types->has('film'))->toBeFalse();

    event(new JobProcessing('database', new SyncJob(app(), '{}', 'database', 'default')));

    expect($types->has('film'))->toBeTrue();
});

it('sees another process’s change at the next request, not before', function (): void {
    $types = app(ContentTypeRegistry::class);
    expect($types->has('film'))->toBeFalse();

    ctStoreRow(ctDefinition());

    expect($types->has('film'))->toBeFalse();

    $this->get('/admin/login')->assertOk();

    expect($types->has('film'))->toBeTrue();
});

it('changes its stamp when the types change, and only then', function (): void {
    $types = app(ContentTypeRegistry::class);
    // A migration read the registry before its table existed
    // (2026_10_18_100100_add_comment_status_to_contents.php:27): read it afresh first.
    $types->refreshStored();
    $types->stamp();
    $first = $types->stamp();
    $types->refreshStored();

    expect($types->stamp())->toBe($first);

    ctStoreRow(ctDefinition());
    $types->refreshStored();
    $second = $types->stamp();

    expect($second)->not->toBe($first);

    $types->register(new ContentType('event', 'Event', 'Events', 'events'));

    expect($types->stamp())->not->toBe($second);
});

it('leaves out a stored type that clashes with code or names what isn’t there, and keeps why', function (): void {
    $this->freezeTime();
    ctStoreRow(ctDefinition(['key' => 'post', 'segment' => 'stories']));
    ctStoreRow(ctDefinition(['key' => 'clip', 'segment' => 'media']));
    ctStoreRow(ctDefinition(['key' => 'reel', 'segment' => 'reels', 'taxonomies' => ['topic']]));
    ctStoreRow(ctDefinition());
    $types = app(ContentTypeRegistry::class);
    $types->refreshStored();

    expect($types->get('post')->stored)->toBeFalse()
        ->and($types->has('clip'))->toBeFalse()
        ->and($types->has('reel'))->toBeFalse()
        ->and($types->has('film'))->toBeTrue()
        ->and($types->refused())->toBe([
            'clip' => 'Content type [clip] cannot use the reserved segment [media].',
            'post' => 'Content type [post] is already registered.',
            'reel' => 'Content type [reel] uses taxonomy [topic], which is not registered.',
        ])
        ->and(app(StoredTypesRefused::class)->ability())->toBe('types.manage')
        ->and(app(StoredTypesRefused::class)->banner()?->message)->toBe('3 saved types can’t be used: “clip”, “post” and “reel”.')
        ->and(app(StoredTypesRefused::class)->banner()?->level)->toBe('error');
});

it('names the reason when one saved type can’t be used, and a row it can’t read', function (): void {
    DB::table('content_type_definitions')->insert(['key' => 'odd', 'definition' => json_encode(['segment' => 'odds', 'public' => 'yes']), 'created_at' => now(), 'updated_at' => now()]);
    ctStoreRow(ctDefinition());
    $types = app(ContentTypeRegistry::class);
    $types->refreshStored();

    expect($types->has('film'))->toBeTrue()
        ->and($types->refused())->toBe(['odd' => '[singular] is missing. [plural] is missing. [public] must be true or false.'])
        ->and(app(StoredTypesRefused::class)->banner()?->message)->toBe('The saved type “odd” can’t be used: [singular] is missing. [plural] is missing. [public] must be true or false.');
});

it('shows no banner when every saved type can be used', function (): void {
    ctStoreRow(ctDefinition());
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(StoredTypesRefused::class)->banner())->toBeNull();
});

it('has no stored types, and keeps no copy, before its table exists', function (): void {
    Schema::drop('content_type_definitions');
    Cache::forget(StoredTypes::CACHE_KEY);
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(ContentTypeRegistry::class)->keys())->toBe(['post', 'page', 'region'])
        ->and(Cache::has(StoredTypes::CACHE_KEY))->toBeFalse();
});

it('asks nothing of the database for what boot checks', function (): void {
    $statements = dashQueries(function (): void {
        typesFresh();
        app(ContentTypeRegistry::class)->fromCode();
        app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics'));
        app(TypesServiceProvider::class, ['app' => app()])->verify();
        app(TypeSchemas::class)->verify();
    });

    expect($statements)->toBe([]);
});
