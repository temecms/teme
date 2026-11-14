<?php

declare(strict_types=1);

use App\Domain\Status\Checks\StoredTaxonomiesRefused;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    ctCreate($this->admin, ['taxonomies' => []]);
    $this->form = [
        'singular' => 'Studio', 'plural' => 'Studios', 'segment' => 'studios', 'icon' => 'building',
        'hierarchical' => '0', 'public' => '1', 'base' => 'studio', 'types' => ['film'],
    ];
    // Genres, used by Films and Posts, with two terms: Action, and Martial arts under it.
    $this->genres = function (): void {
        txCreate($this->admin, [], ['film', 'post']);
        $action = typesTerm($this->admin, 'genre', 'Action');
        typesTerm($this->admin, 'genre', 'Martial arts', $action->id);
        $this->action = $action;
    };
});

it('lists code’s taxonomies first, then the site’s, each with how its terms are arranged, who uses it, its live address and its count', function (): void {
    ($this->genres)();
    cmSettings(['base_genre' => 'kind'], 'addresses');
    app()->forgetScopedInstances();

    $this->actingAs($this->admin)->get('/admin/taxonomies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('taxonomies/index')
            ->where('locked', false)
            ->has('taxonomies', 3)
            ->where('taxonomies.0.key', 'category')
            ->where('taxonomies.0.source', 'code')
            ->where('taxonomies.0.hierarchical', true)
            ->where('taxonomies.0.usedBy', ['Posts'])
            ->where('taxonomies.0.address', '/category/{slug}/')
            ->where('taxonomies.1.key', 'tag')
            ->where('taxonomies.1.hierarchical', false)
            ->where('taxonomies.2', ['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'icon' => 'folder', 'source' => 'stored', 'reason' => null, 'hierarchical' => true, 'usedBy' => ['Posts', 'Films'], 'address' => '/kind/{slug}/', 'terms' => 2, 'termsUrl' => '/admin/genres']));
});

it('lists a taxonomy that isn’t public with no address, and one made later after the older ones', function (): void {
    ($this->genres)();
    txCreate($this->admin, ['key' => 'badge', 'singular' => 'Badge', 'plural' => 'Badges', 'segment' => 'badges', 'public' => false, 'base' => null, 'hierarchical' => false], []);
    // Rows made in one transaction share a clock: put Genres a day before Badges.
    DB::table(StoredTaxonomies::TABLE)->where('key', 'genre')->update(['created_at' => '2026-11-02 10:00:00']);
    DB::table(StoredTaxonomies::TABLE)->where('key', 'badge')->update(['created_at' => '2026-11-03 10:00:00']);

    $this->actingAs($this->admin)->get('/admin/taxonomies')
        ->assertInertia(fn (Assert $page) => $page
            ->where('taxonomies.2.key', 'genre')
            ->where('taxonomies.3.key', 'badge')
            ->where('taxonomies.3.address', null)
            ->where('taxonomies.3.usedBy', [])
            ->where('taxonomies.3.terms', 0));
});

it('shows a saved taxonomy that can’t be used, with why, to be fixed', function (): void {
    txStoreRow(txDefinition(['segment' => 'posts']));
    app(ContentTypeRegistry::class)->refreshStored();

    $this->actingAs($this->admin)->get('/admin/taxonomies')
        ->assertInertia(fn (Assert $page) => $page
            ->where('taxonomies.2.key', 'genre')
            ->where('taxonomies.2.source', 'refused')
            ->where('taxonomies.2.reason', 'Taxonomy [genre] cannot use segment [posts]: a content type has it.')
            ->where('taxonomies.2.termsUrl', null));

    $this->actingAs($this->admin)->get('/admin/taxonomies/genre')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('taxonomies/show')
            ->where('mode', 'edit')
            ->where('source', 'refused')
            ->where('reason', 'Taxonomy [genre] cannot use segment [posts]: a content type has it.')
            ->where('taxonomy.segment', 'posts')
            ->where('termsUrl', null));
});

it('opens Genres in edit with its terms, those under others, an example and which ticked types are locked', function (): void {
    ($this->genres)();
    typesDraft($this->admin, 'film', 'Arrival', [$this->action->id]);

    $this->actingAs($this->admin)->get('/admin/taxonomies/genre')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('taxonomies/show')
            ->where('mode', 'edit')
            ->where('source', 'stored')
            ->where('reason', null)
            ->where('taxonomy', ['key' => 'genre', 'singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'icon' => 'folder', 'hierarchical' => true, 'public' => true, 'base' => 'genre', 'types' => ['post', 'film']])
            ->where('terms', 2)
            ->where('under', 1)
            ->where('address', '/genre/{slug}/')
            ->where('example', 'action')
            ->where('termsUrl', '/admin/genres')
            ->where('made', fn ($made): bool => is_string($made) && preg_match('/^\d{1,2} \p{L}+ \d{4}$/u', $made) === 1)
            ->where('options.types', [
                ['key' => 'post', 'plural' => 'Posts', 'locked' => false],
                ['key' => 'page', 'plural' => 'Pages', 'locked' => false],
                ['key' => 'film', 'plural' => 'Films', 'locked' => true],
            ]));
});

it('opens Categories read-only, and a new taxonomy blank', function (): void {
    $this->actingAs($this->admin)->get('/admin/taxonomies/category')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('taxonomies/show')
            ->where('mode', 'view')
            ->where('source', 'code')
            ->where('made', null)
            ->where('taxonomy.plural', 'Categories')
            ->where('taxonomy.types', ['post'])
            ->where('address', '/category/{slug}/')
            ->where('termsUrl', '/admin/categories'));

    $this->actingAs($this->admin)->get('/admin/taxonomies/new-taxonomy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('taxonomies/show')
            ->where('mode', 'new')
            ->where('source', null)
            ->where('taxonomy', ['key' => '', 'singular' => '', 'plural' => '', 'segment' => '', 'icon' => 'tag', 'hierarchical' => false, 'public' => true, 'base' => null, 'types' => []])
            ->where('made', null)
            ->where('terms', 0)
            ->where('options.types.0', ['key' => 'post', 'plural' => 'Posts', 'locked' => false]));
});

it('shows every page read-only and offers no new taxonomy while locked', function (): void {
    ($this->genres)();
    config(['teme.types_locked' => true]);

    $this->actingAs($this->admin)->get('/admin/taxonomies')->assertInertia(fn (Assert $page) => $page->where('locked', true));
    $this->actingAs($this->admin)->get('/admin/taxonomies/genre')->assertInertia(fn (Assert $page) => $page->where('mode', 'view')->where('source', 'stored'));
    $this->actingAs($this->admin)->get('/admin/taxonomies/category')->assertInertia(fn (Assert $page) => $page->where('mode', 'view'));
    $this->actingAs($this->admin)->get('/admin/taxonomies/new-taxonomy')->assertRedirect('/admin/taxonomies');

    $this->actingAs($this->admin)->post('/admin/taxonomies', [...$this->form, 'key' => 'studio'])->assertForbidden();
    $this->actingAs($this->admin)->put('/admin/taxonomies/genre', [...$this->form, 'plural' => 'Kinds'])->assertForbidden();
    $this->actingAs($this->admin)->delete('/admin/taxonomies/genre')->assertForbidden();

    expect(DB::table(StoredTaxonomies::TABLE)->pluck('key')->all())->toBe(['genre']);
});

it('makes, saves and deletes through the forms, with their toasts', function (): void {
    $this->actingAs($this->admin)->post('/admin/taxonomies', [...$this->form, 'key' => 'studio'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/taxonomies/studio')
        ->assertInertiaFlash('toast.message', 'Added Studios.');

    expect(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['studio']);

    $this->actingAs($this->admin)->put('/admin/taxonomies/studio', $this->form)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/taxonomies/studio')
        ->assertInertiaFlash('toast.message', 'Nothing changed.');

    $this->actingAs($this->admin)->put('/admin/taxonomies/studio', [...$this->form, 'plural' => 'Companies', 'key' => 'ignored'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Saved Companies.');

    expect(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['studio'])
        ->and(app(TaxonomyRegistry::class)->has('ignored'))->toBeFalse();

    $this->actingAs($this->admin)->delete('/admin/taxonomies/studio')
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/taxonomies')
        ->assertInertiaFlash('toast.message', 'Deleted Companies.');

    expect(DB::table(StoredTaxonomies::TABLE)->count())->toBe(0)
        ->and(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe([]);
});

it('sends a refusal back under its field and changes nothing', function (): void {
    ($this->genres)();
    typesDraft($this->admin, 'film', 'Arrival', [$this->action->id]);

    $this->actingAs($this->admin)->from('/admin/taxonomies/genre')
        ->put('/admin/taxonomies/genre', ['singular' => 'Genre', 'plural' => 'Genres', 'segment' => 'genres', 'icon' => 'folder', 'hierarchical' => '1', 'public' => '1', 'types' => ['post']])
        ->assertRedirect('/admin/taxonomies/genre')
        ->assertSessionHasErrors(['types' => 'Films has items with genres chosen, so it can’t be taken off. Remove the genres from its items first.']);

    $this->actingAs($this->admin)->from('/admin/taxonomies/new-taxonomy')
        ->post('/admin/taxonomies', [...$this->form, 'key' => 'Studio'])
        ->assertRedirect('/admin/taxonomies/new-taxonomy')
        ->assertSessionHasErrors('key');

    $this->actingAs($this->admin)->from('/admin/taxonomies/genre')->delete('/admin/taxonomies/genre')
        ->assertRedirect('/admin/taxonomies/genre')
        ->assertSessionHasErrors(['key' => 'Genres have 2 terms, so the taxonomy can’t be deleted. Delete them first.']);

    expect(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['genre'])
        ->and(DB::table(StoredTaxonomies::TABLE)->pluck('key')->all())->toBe(['genre']);
});

it('answers 403 to an editor, and 404 for a key nobody has', function (): void {
    ($this->genres)();

    foreach (['/admin/taxonomies', '/admin/taxonomies/new-taxonomy', '/admin/taxonomies/genre'] as $path) {
        $this->actingAs($this->editor)->get($path)->assertForbidden();
    }

    $this->actingAs($this->editor)->post('/admin/taxonomies', [...$this->form, 'key' => 'studio'])->assertForbidden();
    $this->actingAs($this->editor)->put('/admin/taxonomies/genre', [...$this->form, 'plural' => 'Kinds'])->assertForbidden();
    $this->actingAs($this->editor)->delete('/admin/taxonomies/genre')->assertForbidden();

    $this->actingAs($this->admin)->get('/admin/taxonomies/studio')->assertNotFound();
    $this->actingAs($this->admin)->put('/admin/taxonomies/studio', $this->form)->assertNotFound();
    $this->actingAs($this->admin)->delete('/admin/taxonomies/studio')->assertNotFound();

    expect(DB::table(StoredTaxonomies::TABLE)->pluck('key')->all())->toBe(['genre'])
        ->and(app(TaxonomyRegistry::class)->get('genre')->plural)->toBe('Genres');
});

it('puts Taxonomies between Types and People in the menu, for those who may manage types', function (): void {
    $nav = fn (User $person): array => $this->actingAs($person)->get(route('dashboard'))->viewData('page')['props']['nav'];
    $site = array_values(array_filter($nav($this->admin), fn (array $entry): bool => $entry['section'] === 'site'));

    expect(array_column($site, 'key'))->toBe(['themes', 'plugins', 'types', 'taxonomies', 'users', 'tools', 'settings'])
        ->and($site[3])->toMatchArray(['label' => 'Taxonomies', 'icon' => 'tags', 'href' => '/admin/taxonomies'])
        ->and(array_column($nav($this->editor), 'key'))->not->toContain('taxonomies');
});

it('gives the term screen its Set up link for those who may manage types, and each term its public address', function (): void {
    ($this->genres)();
    txCreate($this->admin, ['key' => 'badge', 'singular' => 'Badge', 'plural' => 'Badges', 'segment' => 'badges', 'public' => false, 'base' => null, 'hierarchical' => false], []);
    typesTerm($this->admin, 'badge', 'Staff pick');

    $this->actingAs($this->admin)->get('/admin/genres')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('terms/index')
            ->where('setup', '/admin/taxonomies/genre')
            ->where('rows.0.name', 'Action')
            ->where('rows.0.address', '/genre/action/')
            ->where('rows.1.address', '/genre/action/martial-arts/'));
    $this->actingAs($this->admin)->get('/admin/categories')
        ->assertInertia(fn (Assert $page) => $page->where('setup', '/admin/taxonomies/category'));
    $this->actingAs($this->editor)->get('/admin/genres')
        ->assertInertia(fn (Assert $page) => $page->where('setup', null));
    $this->actingAs($this->admin)->get('/admin/badges')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.name', 'Staff pick')->where('rows.0.address', null));
});

it('points the Status banner at Taxonomies', function (): void {
    txStoreRow(txDefinition(['segment' => 'posts']));
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(StoredTaxonomiesRefused::class)->banner()?->actionLabel)->toBe('Open Taxonomies')
        ->and(app(StoredTaxonomiesRefused::class)->banner()?->actionUrl)->toBe(route('taxonomies.index'));
});
