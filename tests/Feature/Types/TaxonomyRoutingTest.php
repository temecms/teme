<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Models\Term;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    txStoreRow(txDefinition());
    ctStoreRow(ctDefinition(['taxonomies' => ['genre']]));
    app(ContentTypeRegistry::class)->refreshStored();
});

it('opens a taxonomy made after the routes were registered, at its own segment, with a count column for its type', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/genres')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('terms/index')
            ->where('taxonomy', 'genres')
            ->where('declared', ['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'hierarchical' => true])
            ->where('types.0.key', 'film'));
});

it('adds, changes and deletes its terms at their usual addresses', function (): void {
    $this->actingAs($this->admin)->post(route('terms.store', ['taxonomy' => 'genres']), ['name' => 'Action'])
        ->assertRedirect(route('terms.index', ['taxonomy' => 'genres']));
    $action = Term::query()->where('taxonomy', 'genre')->sole();

    $this->actingAs($this->admin)->put(route('terms.update', ['taxonomy' => 'genres', 'term' => $action->id]), ['name' => 'Action and adventure'])->assertRedirect();
    expect($action->fresh()->name)->toBe('Action and adventure');

    $this->actingAs($this->admin)->delete(route('terms.destroy', ['taxonomy' => 'genres', 'term' => $action->id]))->assertRedirect();
    expect(Term::query()->where('taxonomy', 'genre')->count())->toBe(0);
});

it('adds a term from the editor’s tree picker and answers with it', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/genres', ['name' => 'Drama'])
        ->assertCreated()
        ->assertJsonPath('name', 'Drama');
});

it('keeps a type’s screens at theirs', function (): void {
    $this->actingAs($this->admin)->get('/admin/films')->assertOk()->assertInertia(fn (Assert $page) => $page->component('content/index'));
    $this->actingAs($this->admin)->post('/admin/films', ['title' => 'Arrival'])->assertRedirect();
});

it('asks the term screen’s own permission of a taxonomy’s, and the type’s of a type’s', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');

    $this->actingAs($contributor)->get('/admin/genres')->assertForbidden();
    $this->actingAs($contributor)->post('/admin/genres', ['name' => 'Drama'])->assertForbidden();
    expect(Term::query()->where('taxonomy', 'genre')->count())->toBe(0);
    $this->actingAs($contributor)->get('/admin/films')->assertOk();
});

it('answers 404 for a segment nothing has', function (): void {
    $this->actingAs($this->admin)->get('/admin/studios')->assertNotFound();
});

it('still gives every named screen’s address, which now answers through the screens’ routes', function (): void {
    expect(route('terms.index', ['taxonomy' => 'genres'], false))->toBe('/admin/genres')
        ->and(route('content.index', ['type' => 'films'], false))->toBe('/admin/films')
        ->and(route('terms.destroy', ['taxonomy' => 'genres', 'term' => '01J'], false))->toBe('/admin/genres/01J')
        ->and(route('content.destroy', ['type' => 'films', 'content' => '01J'], false))->toBe('/admin/films/01J');
});
