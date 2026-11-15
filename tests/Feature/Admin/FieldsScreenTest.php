<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBP_RELEASE = '01K9F1E1D0000000000000RD01';
const FBP_RUNTIME = '01K9F1E1D0000000000000RT02';
const FBP_TRAILER = '01K9F1E1D0000000000000TR04';
const FBP_MOOD = '01K9F1E1D0000000000000MD07';

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    ctStoreRow(ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'taxonomies' => [], 'supports' => ['seo', 'excerpt']]));
    app(ContentTypeRegistry::class)->refreshStored();
    StoredFieldRows::store(StoredFieldRows::entry(FBP_RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBP_RUNTIME, 'runtime', ['fieldType' => 'number']), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FBP_TRAILER, 'trailer_url', ['label' => 'Trailer URL', 'fieldType' => 'url']), 3);
    fbpMovie($this->editor, 'Arrival', [FBP_RELEASE => '2016-11-11', FBP_TRAILER => 'https://a.test/t']);
    fbpMovie($this->editor, 'Sicario', [FBP_RELEASE => '2015-10-02']);
    StoredFieldRows::remove(FBP_TRAILER);
    $this->form = [
        'label' => 'Rating', 'handle' => 'rating', 'help' => '', 'fieldType' => 'select',
        'settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG']]],
        'required' => '0', 'search' => '1', 'filterable' => '1', 'show' => 'after',
    ];
});

/**
 * A movie draft holding these values, saved as the editor saves it.
 *
 * @param  array<string, mixed>  $fields
 */
function fbpMovie(User $author, string $title, array $fields): void
{
    $movie = lifecycleDraft($author, $title, 'movie')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $author->id));
}

/** The key of the movie's live field with this handle. */
function fbpKey(string $handle): string
{
    return (string) DB::table(StoredFields::TABLE)->where('content_type', 'movie')->where('handle', $handle)->value('key');
}

it('shows Movies’ fields in order, then Teme’s, then removed ones, to a person who manages types', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/movie/fields')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('types/fields')
            ->where('type.plural', 'Movies')
            ->where('type.noun', 'movie')
            ->where('type.typeUrl', '/admin/types/movie')
            ->has('fields', 2)
            ->where('fields.0.handle', 'release_date')->where('fields.0.summary', 'Date only')->where('fields.0.holding', 2)->where('fields.0.position', 1)
            ->where('fields.0.settings', ['dateOnly' => true])->where('fields.0.required', true)
            ->where('fields.1.handle', 'runtime')->where('fields.1.holding', 0)->where('fields.1.position', 2)
            ->where('fromTeme.0.name', 'Excerpt')->where('fromTeme.0.where', 'Under the title')->where('fromTeme.0.fieldType', 'Long text')
            ->where('fromTeme.1.name', 'Search and social')->where('fromTeme.1.handles', 'seo_title, meta_description and 11 more')->where('fromTeme.1.fieldType', 'Several')
            ->has('fromTeme', 2)
            ->where('removed.0.handle', 'trailer_url')->where('removed.0.holding', 1)
            ->where('unreadable', [])
            ->has('catalogue', 9)->where('locked', false));
});

it('shows Posts’ page too, with the built-ins as Teme’s', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/post/fields')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('types/fields')
            ->where('fields', [])
            ->where('fromTeme.0.name', 'Excerpt')
            ->where('fromTeme.1.name', 'Featured image')
            ->where('fromTeme.2.name', 'Search and social')
            ->where('fromTeme.2.handles', 'seo_title, meta_description and 11 more')
            ->where('fromTeme.3.name', 'Template')
            ->where('fromTeme.4.name', 'Pin to the top of the latest posts')
            ->where('fromTeme.4.where', 'On the list, not in the editor')
            ->where('fromTeme.5.name', 'No inserted content')
            ->where('fromTeme.5.handles', 'insertions_off')
            ->has('fromTeme', 6));
});

it('lists a stored field that can’t be read in its own group, saying why (#324)', function (): void {
    DB::table(StoredFields::TABLE)->insert(['key' => FBP_MOOD, 'content_type' => 'movie', 'handle' => 'mood', 'position' => 3, 'definition' => '{"fieldType":"text"}', 'created_at' => now(), 'updated_at' => now()]);
    app(StoredFields::class)->remember();
    app(FieldRegistry::class)->refreshStored();

    $this->actingAs($this->admin)->get('/admin/types/movie/fields')
        ->assertInertia(fn (Assert $page) => $page
            ->where('unreadable.0.handle', 'mood')
            ->where('unreadable.0.sentence', 'The saved field “mood” can’t be used: [label] is missing.')
            ->has('fields', 2)
            ->where('fields.1.handle', 'runtime'));
});

it('answers 404 for a type that doesn’t exist, and 403 to an editor', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/ghost/fields')->assertNotFound();
    $this->actingAs($this->editor)->get('/admin/types/movie/fields')->assertForbidden();
    $this->actingAs($this->editor)->post('/admin/types/movie/fields', $this->form)->assertForbidden();
});

it('adds, saves, moves, removes and restores through their Operations, each telling what it did', function (): void {
    $this->actingAs($this->admin)->post('/admin/types/movie/fields', $this->form)
        ->assertRedirect('/admin/types/movie/fields')
        ->assertInertiaFlash('toast.message', 'Added Rating.');
    $key = fbpKey('rating');

    expect(app(FieldRegistry::class)->find($key)?->options)->toBe(['' => 'None', 'g' => 'G', 'pg' => 'PG']);

    $this->actingAs($this->admin)->put("/admin/types/movie/fields/{$key}", [...$this->form, 'label' => 'Age rating'])
        ->assertRedirect('/admin/types/movie/fields')
        ->assertInertiaFlash('toast.message', 'Saved Age rating.');
    $this->actingAs($this->admin)->put("/admin/types/movie/fields/{$key}", [...$this->form, 'label' => 'Age rating'])
        ->assertInertiaFlash('toast.message', 'Nothing changed.');

    $this->actingAs($this->admin)->put("/admin/types/movie/fields/{$key}/position", ['position' => 1])
        ->assertRedirect('/admin/types/movie/fields')
        ->assertInertiaFlash('toast.message', 'Moved Age rating.');

    expect(DB::table(StoredFields::TABLE)->whereNull('removed_at')->orderBy('position')->pluck('handle')->all())->toBe(['rating', 'release_date', 'runtime']);

    $this->actingAs($this->admin)->delete("/admin/types/movie/fields/{$key}")
        ->assertInertiaFlash('toast.message', 'Removed Age rating.');
    $this->actingAs($this->admin)->post("/admin/types/movie/fields/{$key}/restore")
        ->assertInertiaFlash('toast.message', 'Restored Age rating.');

    expect(DB::table(StoredFields::TABLE)->whereNull('removed_at')->orderBy('position')->pluck('handle')->all())->toBe(['release_date', 'runtime', 'rating']);
});

it('keeps the sheet’s input and says what’s wrong beside it when a field is refused', function (): void {
    $this->actingAs($this->admin)->from('/admin/types/movie/fields')->post('/admin/types/movie/fields', [...$this->form, 'handle' => 'excerpt'])
        ->assertRedirect('/admin/types/movie/fields')
        ->assertSessionHasErrors(['handle' => 'excerpt is one of Teme’s own fields, so a field of yours can’t use it.']);

    expect(DB::table(StoredFields::TABLE)->count())->toBe(3);
});

it('shows every page read-only while the types are locked', function (): void {
    config(['teme.types_locked' => true]);

    $this->actingAs($this->admin)->get('/admin/types/movie/fields')
        ->assertInertia(fn (Assert $page) => $page->where('locked', true));
    $this->actingAs($this->admin)->post('/admin/types/movie/fields', $this->form)->assertForbidden();

    expect(DB::table(StoredFields::TABLE)->count())->toBe(3);
});

it('gives a type’s page the summary of its fields and the Fields page’s address', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/movie')
        ->assertInertia(fn (Assert $page) => $page->component('types/show')
            ->where('fields.own', ['Release date', 'Runtime'])
            ->where('fields.fromTeme', ['Excerpt', 'Search and social'])
            ->where('fields.url', '/admin/types/movie/fields'));

    $this->actingAs($this->admin)->get('/admin/types/post')
        ->assertInertia(fn (Assert $page) => $page
            ->where('fields.own', [])
            ->where('fields.fromTeme', ['Excerpt', 'Featured image', 'Search and social', 'Template', 'Pin to the top of the latest posts'])
            ->where('fields.url', '/admin/types/post/fields'));

    $this->actingAs($this->admin)->get('/admin/types/new-type')
        ->assertInertia(fn (Assert $page) => $page->where('fields.url', null)->where('fields.own', []));
});
