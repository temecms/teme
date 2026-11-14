<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->note = ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'icon' => 'sticky-note', 'public' => false, 'archive' => false, 'searchable' => false, 'taxonomies' => [], 'supports' => [], 'comments' => null];
    $this->import = fn (array $file, ?User $actor = null): array => $actor === null
        ? scElevated(fn (): array => app(ImportTypes::class)->execute(new ImportTypesInput($file)))
        : app(ImportTypes::class)->execute(new ImportTypesInput($file, (string) $actor->id));
});

it('exports the site’s own types as version 1, never code’s, with room for what later versions add', function (): void {
    $this->freezeTime();
    ctCreate($this->admin);
    ctCreate($this->admin, $this->note);

    expect(TypesFile::export())->toEqual(ctFile([
        ctDefinition(['pattern' => '/films/{slug}/'])->toArray(),
        ctDefinition($this->note)->toArray(),
    ]));
});

it('adds every type a file names to a site without them, live at once', function (): void {
    expect(($this->import)(ctFile([ctDefinition()->toArray()])))->toBe(['added' => ['film'], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'patternKept' => [], 'grantsKept' => [], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []], 'fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []])
        ->and(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/films/{slug}/');
});

it('changes nothing when the file matches the site', function (): void {
    ($this->import)(ctFile([ctDefinition()->toArray()]));
    $this->travel(1)->minutes();
    $before = DB::table('content_type_definitions')->value('updated_at');

    expect(($this->import)(ctFile([ctDefinition()->toArray()])))->toBe(['added' => [], 'updated' => [], 'unchanged' => ['film'], 'onlyHere' => [], 'patternKept' => [], 'grantsKept' => [], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []], 'fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []])
        ->and(DB::table('content_type_definitions')->value('updated_at'))->toBe($before);
});

it('updates what differs, and keeps the site’s own address pattern, saying so', function (): void {
    ctCreate($this->admin);

    expect(($this->import)(ctFile([ctDefinition(['plural' => 'Movies', 'pattern' => '/watch/{slug}/'])->toArray()])))
        ->toBe(['added' => [], 'updated' => ['film'], 'unchanged' => [], 'onlyHere' => [], 'patternKept' => ['film'], 'grantsKept' => [], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []], 'fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []])
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Movies')
        ->and(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/films/{slug}/');
});

it('gives a type the file makes public the file’s address pattern, without saying it kept one', function (): void {
    ctCreate($this->admin, ['public' => false, 'archive' => false, 'searchable' => false, 'comments' => null]);

    expect(($this->import)(ctFile([ctDefinition(['pattern' => '/watch/{slug}/'])->toArray()])))
        ->toBe(['added' => [], 'updated' => ['film'], 'unchanged' => [], 'onlyHere' => [], 'patternKept' => [], 'grantsKept' => [], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []], 'fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []])
        ->and(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/watch/{slug}/');
});

it('leaves a type only this site has where it is, and names it', function (): void {
    ctCreate($this->admin, $this->note);

    expect(($this->import)(ctFile([ctDefinition()->toArray()]))['onlyHere'])->toBe(['note'])
        ->and(app(ContentTypeRegistry::class)->has('note'))->toBeTrue();
});

it('refuses the whole file for one type it can’t make, and writes nothing', function (): void {
    expect(fn () => ($this->import)(ctFile([ctDefinition()->toArray(), ctDefinition(['key' => 'clip', 'segment' => 'media'])->toArray()])))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['types.1.segment'])->toContain('/admin/media is one of the admin’s own screens.');
        });

    expect(DB::table('content_type_definitions')->count())->toBe(0);
});

it('refuses a file it can’t read, saying why, and writes nothing', function (array $file, string $field, string $message): void {
    expect(fn () => ($this->import)($file))->toThrow(function (ValidationException $refusal) use ($field, $message): void {
        expect($refusal->errors()[$field] ?? [])->toContain($message);
    });

    expect(DB::table('content_type_definitions')->count())->toBe(0);
})->with([
    'another kind of file' => [['format' => 'wxr', 'version' => 1, 'types' => []], 'file', 'This isn’t a teme-types.json file: it needs "format": "teme-types".'],
    'a later version' => [ctFile([], ['version' => 2]), 'file', 'This file is version 2; this version of Teme reads version 1.'],
    'no version' => [array_diff_key(ctFile([]), ['version' => true]), 'file', 'This file doesn’t say its version: it needs "version": 1.'],
    'something else at the top' => [ctFile([], ['menus' => []]), 'file', '[menus] isn’t part of a version 1 file.'],
    'fields it can’t read' => [ctFile([], ['fields' => [['key' => 'rating']]]), 'fields.0', 'Field 1: [type] is missing. [handle] is missing. [label] is missing. [fieldType] is missing.'],
    'a taxonomy it can’t read' => [ctFile([], ['taxonomies' => [['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'hierarchical' => 'yes']]]), 'taxonomies.0', 'genre: [hierarchical] must be true or false.'],
    'grants it can’t read' => [ctFile([], ['grants' => [['role' => 'editor']]]), 'grants.0', 'Grant 1: [type] must be text.'],
    'types that aren’t a list' => [ctFile([], ['types' => ['film' => []]]), 'types', '[types] must be a list of types.'],
    'a type it can’t read' => [ctFile([['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'public' => 'yes']]), 'types.0', 'film: [public] must be true or false.'],
    'one key twice' => [ctFile([['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films'], ['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'movies']]), 'types.1.key', 'The file has the key film twice.'],
    'one segment twice' => [ctFile([['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films'], ['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'films']]), 'types.1.segment', 'The file gives /admin/films to two types.'],
    'a key code declares' => [ctFile([['key' => 'post', 'singular' => 'Story', 'plural' => 'Stories', 'segment' => 'stories']]), 'types.0.key', 'post is declared by code on this site, so a file can’t bring it.'],
]);

it('mends a saved type nobody can read by putting the file’s in its place', function (): void {
    DB::table('content_type_definitions')->insert(['key' => 'film', 'definition' => json_encode(['segment' => 'films', 'public' => 'yes']), 'created_at' => now(), 'updated_at' => now()]);
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(ContentTypeRegistry::class)->refused())->toHaveKey('film')
        ->and(($this->import)(ctFile([ctDefinition()->toArray()])))->toBe(['added' => [], 'updated' => ['film'], 'unchanged' => [], 'onlyHere' => [], 'patternKept' => [], 'grantsKept' => [], 'taxonomies' => ['added' => [], 'updated' => [], 'unchanged' => [], 'onlyHere' => [], 'baseKept' => []], 'fieldsAdded' => [], 'fieldsUpdated' => [], 'fieldsUnchanged' => [], 'fieldsOnlyHere' => []])
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Films')
        ->and(app(ContentTypeRegistry::class)->refused())->toBe([]);
});

it('holds a file’s change to the rules a change on the site meets', function (): void {
    ctCreate($this->admin);
    lifecycleDraft($this->admin, 'Arrival', 'film');

    expect(fn () => ($this->import)(ctFile([ctDefinition(['public' => false, 'archive' => false, 'searchable' => false, 'comments' => null])->toArray()])))
        ->toThrow(function (ValidationException $refusal): void {
            expect($refusal->errors()['types.0.public'])->toContain('Films have items (1, trashed ones included), so whether they’re public can’t change.');
        });

    expect(app(ContentTypeRegistry::class)->get('film')->public)->toBeTrue();
});

it('writes nothing when authorization fails to import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->import)(ctFile([ctDefinition()->toArray()]), $editor))->toThrow(AuthorizationException::class);

    expect(DB::table('content_type_definitions')->count())->toBe(0);
});

it('refuses an administrator while the types are locked, and lets the operator import', function (): void {
    config(['teme.types_locked' => true]);

    expect(fn () => ($this->import)(ctFile([ctDefinition()->toArray()]), $this->admin))->toThrow(AuthorizationException::class, 'TEME_TYPES_LOCKED');
    expect(DB::table('content_type_definitions')->count())->toBe(0)
        ->and(($this->import)(ctFile([ctDefinition()->toArray()]))['added'])->toBe(['film']);
});
