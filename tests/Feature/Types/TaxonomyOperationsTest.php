<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\DeleteTaxonomy;
use App\Domain\Types\Operations\Input\DeleteTaxonomyInput;
use App\Domain\Types\Operations\Input\UpdateTaxonomyInput;
use App\Domain\Types\Operations\UpdateTaxonomy;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\TaxonomyDefinition;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ctCreate($this->admin, ['taxonomies' => []]);
    $this->refusal = fn (Closure $run, string $field, string $message) => expect($run)->toThrow(function (ValidationException $refusal) use ($field, $message): void {
        expect($refusal->errors()[$field] ?? [])->toContain($message);
    });
});

it('makes Genres, used by Films and Posts, live at once: Films’ row names it, Posts take it through the taxonomy', function (): void {
    expect(txCreate($this->admin, [], ['film', 'post']))->toBe(['key' => 'genre']);

    $row = json_decode((string) DB::table(StoredTaxonomies::TABLE)->where('key', 'genre')->value('definition'), true);
    expect($row['code_types'])->toBe(['post'])
        ->and($row['base'])->toBe('genre')
        ->and(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['genre'])
        ->and(app(ContentTypeRegistry::class)->get('post')->taxonomies)->toBe(['category', 'tag', 'genre'])
        ->and(app(TaxonomyRegistry::class)->get('genre')->stored)->toBeTrue();
});

it('gives Pages a site taxonomy, and never Categories or Tags', function (): void {
    txCreate($this->admin, ['key' => 'series', 'singular' => 'Series', 'plural' => 'Series', 'segment' => 'series', 'base' => 'series', 'hierarchical' => false], ['page']);

    expect(app(ContentTypeRegistry::class)->get('page')->taxonomies)->toBe(['series']);

    ($this->refusal)(fn () => app(UpdateTaxonomy::class)->execute(new UpdateTaxonomyInput(
        TaxonomyDefinition::fromArray(['key' => 'category', 'singular' => 'Category', 'plural' => 'Categories', 'segment' => 'categories']), ['post', 'page'], (string) $this->admin->id,
    )), 'key', 'Categories are declared by code, so they can’t be changed or deleted here.');

    expect(app(ContentTypeRegistry::class)->get('page')->taxonomies)->not->toContain('category', 'tag');
});

it('writes nothing when authorization fails, for each Operation', function (string $operation): void {
    txCreate($this->admin, [], ['film']);
    $editor = User::factory()->create()->assignRole('editor');
    $before = [DB::table(StoredTaxonomies::TABLE)->get()->all(), DB::table('content_type_definitions')->get()->all()];

    $run = match ($operation) {
        'create' => fn () => txCreate($editor, ['key' => 'studio', 'segment' => 'studios', 'base' => 'studio'], ['film']),
        'update' => fn () => txUpdate($editor, ['plural' => 'Kinds'], []),
        'delete' => fn () => app(DeleteTaxonomy::class)->execute(new DeleteTaxonomyInput('genre', (string) $editor->id)),
    };

    expect($run)->toThrow(AuthorizationException::class)
        ->and([DB::table(StoredTaxonomies::TABLE)->get()->all(), DB::table('content_type_definitions')->get()->all()])->toEqual($before);
})->with(['create', 'update', 'delete']);

it('refuses an administrator while the types are locked, and lets the operator', function (): void {
    config(['teme.types_locked' => true]);

    expect(fn () => txCreate($this->admin))->toThrow(AuthorizationException::class)
        ->and(txCreate(null))->toBe(['key' => 'genre']);
});

it('never renames: an update keeps the row’s key and its terms keep theirs', function (): void {
    txCreate($this->admin, [], ['film']);
    $action = typesTerm($this->admin, 'genre', 'Action');

    txUpdate($this->admin, ['singular' => 'Kind', 'plural' => 'Kinds', 'segment' => 'kinds'], ['film']);

    expect(DB::table(StoredTaxonomies::TABLE)->pluck('key')->all())->toBe(['genre'])
        ->and($action->fresh()->taxonomy)->toBe('genre')
        ->and(app(TaxonomyRegistry::class)->bySegment('kinds')?->key)->toBe('genre');
});

it('keeps the base it was made with; Settings › Addresses changes it', function (): void {
    txCreate($this->admin, [], ['film']);

    txUpdate($this->admin, ['base' => 'kind'], ['film']);

    expect(app(TaxonomyRegistry::class)->get('genre')->base)->toBe('genre');
});

it('won’t turn nested to flat while terms sit under others, and will once none do', function (): void {
    txCreate($this->admin, [], ['film']);
    $action = typesTerm($this->admin, 'genre', 'Action');
    $hero = typesTerm($this->admin, 'genre', 'Superhero', $action->id);

    ($this->refusal)(fn () => txUpdate($this->admin, ['hierarchical' => false], ['film']), 'hierarchical', '1 genre sits under others, so Genres can’t become flat. Move it to the top first.');

    typesTerm($this->admin, 'genre', 'Space opera', typesTerm($this->admin, 'genre', 'Science fiction')->id);
    ($this->refusal)(fn () => txUpdate($this->admin, ['hierarchical' => false], ['film']), 'hierarchical', '2 genres sit under others, so Genres can’t become flat. Move them to the top first.');

    DB::table('terms')->update(['parent_id' => null]);
    expect(txUpdate($this->admin, ['hierarchical' => false], ['film'])['changed'])->toBeTrue();
});

it('won’t take a type off while its items have the taxonomy’s terms, trashed ones included, naming each', function (): void {
    ctCreate($this->admin, ['key' => 'show', 'singular' => 'TV show', 'plural' => 'TV Shows', 'segment' => 'shows', 'taxonomies' => []]);
    txCreate($this->admin, [], ['film', 'show', 'post']);
    $action = typesTerm($this->admin, 'genre', 'Action');
    typesDraft($this->admin, 'film', 'Arrival', [$action->id])->delete();
    typesDraft($this->admin, 'post', 'A review', [$action->id]);

    ($this->refusal)(fn () => txUpdate($this->admin, [], []), 'types', 'Posts and Films have items with genres chosen, so neither can be taken off. Remove the genres from their items first.');

    txUpdate($this->admin, [], ['film', 'post']);
    expect(app(ContentTypeRegistry::class)->get('show')->taxonomies)->toBe([]);
});

it('takes a type off and adds another through the types’ own Operation, in one transaction', function (): void {
    ctCreate($this->admin, ['key' => 'show', 'singular' => 'TV show', 'plural' => 'TV Shows', 'segment' => 'shows', 'taxonomies' => ['category']]);
    txCreate($this->admin, [], ['film']);

    txUpdate($this->admin, [], ['show']);

    expect(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe([])
        ->and(app(ContentTypeRegistry::class)->get('show')->taxonomies)->toBe(['category', 'genre']);
});

it('refuses to add a type whose row nobody can read, writing nothing', function (): void {
    ctCreate($this->admin, ['key' => 'show', 'singular' => 'TV show', 'plural' => 'TV Shows', 'segment' => 'shows', 'taxonomies' => []]);
    txCreate($this->admin, [], ['film']);
    DB::table('content_type_definitions')->where('key', 'show')->update(['definition' => DB::raw("definition || '{\"public\": \"yes\"}'::jsonb")]);

    ($this->refusal)(fn () => txUpdate($this->admin, ['plural' => 'Kinds'], ['film', 'show']), 'types', 'show can’t be read, so whether it uses Genres can’t change here. Fix it on Site › Types first.');

    expect(app(TaxonomyRegistry::class)->get('genre')->plural)->toBe('Genres');
});

it('won’t change whether terms have public pages while it has terms', function (): void {
    txCreate($this->admin, [], ['film']);
    typesTerm($this->admin, 'genre', 'Action');

    ($this->refusal)(fn () => txUpdate($this->admin, ['public' => false, 'base' => null], ['film']), 'public', 'Genres have terms (1), so whether they have public pages can’t change.');
});

it('gives a taxonomy that becomes public the base it is given, checked as a new one’s, never its key by default', function (): void {
    txCreate($this->admin, ['public' => false, 'base' => null], ['film']);

    ($this->refusal)(fn () => txUpdate($this->admin, ['public' => true, 'base' => ''], ['film']), 'base', 'Give the address base: the first part of each genre’s address.');
    ($this->refusal)(fn () => txUpdate($this->admin, ['public' => true, 'base' => 'tag'], ['film']), 'base', 'Addresses starting /tag/ are already in use on this site.');

    txUpdate($this->admin, ['public' => true, 'base' => 'kind'], ['film']);
    expect(app(TaxonomyRegistry::class)->get('genre')->base)->toBe('kind');
});

it('deletes a taxonomy with no terms, taking it off its types, and refuses one with terms', function (): void {
    txCreate($this->admin, [], ['film']);
    $action = typesTerm($this->admin, 'genre', 'Action');
    $delete = fn () => app(DeleteTaxonomy::class)->execute(new DeleteTaxonomyInput('genre', (string) $this->admin->id));

    ($this->refusal)($delete, 'key', 'Genres have 1 term, so the taxonomy can’t be deleted. Delete it first.');

    app(DeleteTerm::class)->execute(new DeleteTermInput((string) $action->id, (string) $this->admin->id));
    expect($delete())->toBe(['key' => 'genre'])
        ->and(app(TaxonomyRegistry::class)->has('genre'))->toBeFalse()
        ->and(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe([]);
});

it('refuses what a taxonomy must not be, writing nothing', function (array $overrides, array $types, string $field, string $message): void {
    ($this->refusal)(fn () => txCreate($this->admin, $overrides, $types), $field, $message);

    expect(DB::table(StoredTaxonomies::TABLE)->count())->toBe(0);
})->with([
    'a key code declares' => [['key' => 'tag'], [], 'key', 'There’s already a taxonomy with the key tag.'],
    'a list’s own query key' => [['key' => 'search'], [], 'key', 'The key search is used by the site’s own lists and addresses, so a taxonomy can’t have it.'],
    'an address token' => [['key' => 'year'], [], 'key', 'The key year is used by the site’s own lists and addresses, so a taxonomy can’t have it.'],
    'a bad key' => [['key' => 'Genre'], [], 'key', 'A key is lower-case letters, digits and underscores, starts with a letter, and is 32 characters at most.'],
    'no name' => [['plural' => ' '], [], 'plural', 'Give the taxonomy a plural name of 60 characters at most.'],
    'an admin screen' => [['segment' => 'media'], [], 'segment', '/admin/media is one of the admin’s own screens.'],
    'a type’s segment' => [['segment' => 'films'], [], 'segment', '/admin/films already shows Films.'],
    'a taxonomy’s segment' => [['segment' => 'tags'], [], 'segment', '/admin/tags already shows Tags.'],
    'a type with no list' => [[], ['region'], 'types', 'Regions have no list of their own, so they can’t use a taxonomy.'],
    'a type twice' => [[], ['film', 'film'], 'types', 'The taxonomy names Films twice.'],
    'no base' => [['base' => ''], [], 'base', 'Give the address base: the first part of each genre’s address.'],
    'a bad base' => [['base' => 'Genre!'], [], 'base', 'Use lower-case letters, numbers and hyphens only.'],
    'the site’s own words' => [['base' => 'admin'], [], 'base', 'Addresses starting /admin/ are used by the site itself.'],
    'another taxonomy’s base' => [['base' => 'tag'], [], 'base', 'Addresses starting /tag/ are already in use on this site.'],
    'a type’s pattern' => [['base' => 'films'], [], 'base', 'Addresses starting /films/ are already in use on this site.'],
]);

it('refuses a segment another stored row holds that this process’s registry hasn’t loaded', function (): void {
    txStoreRow(txDefinition(['key' => 'studio', 'singular' => 'Studio', 'plural' => 'Studios', 'segment' => 'genres', 'base' => 'studio']));

    ($this->refusal)(fn () => txCreate($this->admin), 'segment', '/admin/genres already shows Studios.');
});

it('won’t let a type name a stored taxonomy the registry refuses', function (): void {
    txStoreRow(txDefinition(['segment' => 'posts']));
    app(ContentTypeRegistry::class)->refreshStored();

    ($this->refusal)(fn () => ctCreate($this->admin, ['key' => 'show', 'singular' => 'TV show', 'plural' => 'TV Shows', 'segment' => 'shows', 'taxonomies' => ['genre']]), 'taxonomies', 'There is no taxonomy genre.');
});

it('makes a nested site taxonomy the primary category of Pages and of a stored type, as I-295 has it', function (): void {
    txCreate($this->admin, ['key' => 'section', 'singular' => 'Section', 'plural' => 'Sections', 'segment' => 'sections', 'base' => 'section'], ['page', 'film']);

    expect(PrimaryCategory::taxonomyFor('page'))->toBe('section')
        ->and(PrimaryCategory::taxonomyFor('film'))->toBe('section')
        ->and(PrimaryCategory::taxonomyFor('post'))->toBe('category');
});
