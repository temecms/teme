<?php

declare(strict_types=1);

use App\Domain\Status\Checks\StoredTypesRefused;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTypes;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->form = [
        'key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'clapperboard',
        'single' => '0', 'public' => '1', 'archive' => '1', 'searchable' => '1',
        'taxonomies' => ['category'], 'supports' => ['seo', 'excerpt'], 'comments' => 'closed', 'pattern' => '',
    ];
    $this->unreadable = function (): void {
        DB::table(StoredTypes::TABLE)->insert(['key' => 'event', 'definition' => json_encode(['segment' => 'events', 'icon' => 'calendar', 'public' => 'yes']), 'created_at' => '2026-11-04 10:00:00', 'updated_at' => now()]);
        app(StoredTypes::class)->remember();
        app(ContentTypeRegistry::class)->refreshStored();
    };
});

it('lists every type, code’s first, then the site’s own oldest first, a refused one in the registry’s words', function (): void {
    tbSite($this->admin);

    $this->actingAs($this->admin)->get('/admin/types')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('types/index')
            ->where('locked', false)
            ->has('types', 6)
            ->where('types.0.key', 'post')
            ->where('types.0.source', 'code')
            ->where('types.0.pattern', '/{slug}/')
            ->where('types.0.listUrl', '/admin/posts')
            ->where('types.1.key', 'page')
            ->where('types.2.key', 'region')
            ->where('types.2.public', false)
            ->where('types.2.pattern', null)
            ->where('types.2.listUrl', null)
            ->where('types.3', ['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'film', 'source' => 'stored', 'public' => true, 'pattern' => '/films/{slug}/', 'items' => 3, 'trashed' => 1, 'reason' => null, 'listUrl' => '/admin/films'])
            ->where('types.4.key', 'recipe')
            ->where('types.4.items', 0)
            ->where('types.5', ['key' => 'event', 'singular' => 'Event', 'plural' => 'Events', 'segment' => 'events', 'icon' => 'calendar', 'source' => 'refused', 'public' => true, 'pattern' => '/events/{slug}/', 'items' => 0, 'trashed' => 0, 'reason' => 'Content type [event] uses taxonomy [venue], which is not registered.', 'listUrl' => null]));
});

it('shows a public type’s address as Settings › Addresses has it, not as the type was made', function (): void {
    tbSite($this->admin);
    cmSettings(['pattern_film' => '/watch/{slug}/'], 'addresses');
    // tbSite has already read the scoped address settings; a request starts without them.
    app()->forgetScopedInstances();

    $this->actingAs($this->admin)->get('/admin/types')
        ->assertInertia(fn (Assert $page) => $page->where('types.3.pattern', '/watch/{slug}/'));
    $this->actingAs($this->admin)->get('/admin/types/film')
        ->assertInertia(fn (Assert $page) => $page->where('type.pattern', '/watch/{slug}/'));
});

it('opens a type made here to change, with its items, the day it was made and an example address', function (): void {
    tbSite($this->admin);

    $this->actingAs($this->admin)->get('/admin/types/film')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('types/show')
            ->where('mode', 'edit')
            ->where('source', 'stored')
            ->where('reason', null)
            ->where('type', ['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'film', 'single' => false, 'public' => true, 'archive' => true, 'searchable' => true, 'taxonomies' => ['category'], 'supports' => ['seo', 'excerpt', 'featured_image'], 'comments' => 'closed', 'pattern' => '/films/{slug}/', 'permissions' => 'shared'])
            ->where('made', '2 November 2026')
            ->where('items', 3)
            ->where('trashed', 1)
            ->where('example', '/films/the-long-walk/')
            ->where('listUrl', '/admin/films'));
});

it('shows a type code declares to look at, never to change', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/post')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('types/show')
            ->where('mode', 'view')
            ->where('source', 'code')
            ->where('made', null)
            ->where('type.plural', 'Posts')
            ->where('type.pattern', '/{slug}/')
            ->where('type.comments', 'open')
            ->where('example', null)
            ->where('listUrl', '/admin/posts'));
});

it('opens a saved type nobody can read to be fixed, filled in as far as it can be read', function (): void {
    ($this->unreadable)();

    $this->actingAs($this->admin)->get('/admin/types/event')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('types/show')
            ->where('mode', 'edit')
            ->where('source', 'refused')
            ->where('reason', '[singular] is missing. [plural] is missing. [public] must be true or false.')
            ->where('type', ['key' => 'event', 'singular' => '', 'plural' => '', 'segment' => 'events', 'icon' => 'calendar', 'single' => false, 'public' => true, 'archive' => false, 'searchable' => true, 'taxonomies' => [], 'supports' => [], 'comments' => null, 'pattern' => '/events/{slug}/', 'permissions' => 'shared'])
            ->where('made', '4 November 2026')
            ->where('listUrl', null));
});

it('mends that type from its form, and the registry takes it at once', function (): void {
    ($this->unreadable)();

    $this->actingAs($this->admin)
        ->put('/admin/types/event', [...$this->form, 'key' => 'ignored', 'singular' => 'Event', 'plural' => 'Events', 'segment' => 'events'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/types/event')
        ->assertInertiaFlash('toast.message', 'Saved Events.');

    expect(app(ContentTypeRegistry::class)->refused())->toBe([])
        ->and(app(ContentTypeRegistry::class)->get('event')->plural)->toBe('Events')
        ->and(app(ContentTypeRegistry::class)->has('ignored'))->toBeFalse();
});

it('answers not found for a type nobody has', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/film')->assertNotFound();
});

it('opens New type with the registered taxonomies and every feature a field provides, in the drawing’s words', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/new-type')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('types/show')
            ->where('mode', 'new')
            ->where('source', null)
            ->where('type', ['key' => '', 'singular' => '', 'plural' => '', 'segment' => '', 'icon' => 'file', 'single' => false, 'public' => true, 'archive' => false, 'searchable' => true, 'taxonomies' => [], 'supports' => [], 'comments' => null, 'pattern' => null, 'permissions' => 'shared'])
            ->where('options.taxonomies', [['key' => 'category', 'plural' => 'Categories'], ['key' => 'tag', 'plural' => 'Tags']])
            ->where('options.features.0', ['key' => 'seo', 'label' => 'Search and social', 'hint' => 'SEO title, meta description and the card shown when an item is shared.', 'field' => 'Search and social'])
            ->where('options.features.4', ['key' => 'sticky', 'label' => 'Pinning', 'hint' => 'An item can be pinned to the top of the latest posts.', 'field' => 'Pin to the top of the latest posts']));
});

it('makes a type from the form and opens it, saying so, and the menu draws it with its icon', function (): void {
    $this->actingAs($this->admin)->post('/admin/types', $this->form)
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/types/film')
        ->assertInertiaFlash('toast.message', 'Added Films.');

    $film = app(ContentTypeRegistry::class)->get('film');

    expect($film->stored)->toBeTrue()
        ->and($film->icon)->toBe('clapperboard')
        ->and($film->supports)->toBe(['seo', 'excerpt'])
        ->and($film->pattern)->toBe('/films/{slug}/')
        ->and($film->single)->toBeFalse();

    $this->actingAs($this->admin)->get('/admin/types')
        ->assertInertia(fn (Assert $page) => $page->where('nav', fn ($nav): bool => collect($nav)->contains(fn (array $entry): bool => $entry['key'] === 'films' && $entry['icon'] === 'clapperboard')));
});

it('makes a type with the address pattern the form gives, and one that isn’t public with none', function (): void {
    $this->actingAs($this->admin)->post('/admin/types', [...$this->form, 'pattern' => '/watch/{slug}/'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/admin/types', ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'icon' => 'notebook', 'public' => '0'])->assertSessionHasNoErrors();

    expect(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/watch/{slug}/')
        ->and(app(ContentTypeRegistry::class)->get('note')->public)->toBeFalse()
        ->and(app(ContentTypeRegistry::class)->get('note')->pattern)->toBeNull();
});

it('sends the form’s refusals back under their fields, and makes nothing', function (): void {
    $this->actingAs($this->admin)->from('/admin/types/new-type')
        ->post('/admin/types', [...$this->form, 'key' => 'Film', 'segment' => 'media'])
        ->assertRedirect('/admin/types/new-type')
        ->assertSessionHasErrors([
            'key' => 'A key is lower-case letters, digits and underscores, starts with a letter, and is 32 characters at most.',
            'segment' => '/admin/media is one of the admin’s own screens.',
        ]);

    expect(DB::table(StoredTypes::TABLE)->count())->toBe(0);
});

it('saves a change, says when nothing changed, and keeps the key and address pattern whatever the form sends', function (): void {
    ctCreate($this->admin);
    $form = [...$this->form, 'icon' => 'film', 'supports' => ['seo', 'excerpt', 'featured_image']];

    $this->actingAs($this->admin)->put('/admin/types/film', [...$form, 'plural' => 'Movies', 'key' => 'movie', 'pattern' => '/watch/{slug}/'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/types/film')
        ->assertInertiaFlash('toast.message', 'Saved Movies.');

    expect(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Movies')
        ->and(app(ContentTypeRegistry::class)->has('movie'))->toBeFalse()
        ->and(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/films/{slug}/');

    $this->actingAs($this->admin)->put('/admin/types/film', [...$form, 'plural' => 'Movies'])
        ->assertInertiaFlash('toast.message', 'Nothing changed.');
});

it('sends back why whether Films are public can’t change while they have items', function (): void {
    ctCreate($this->admin);
    lifecycleDraft($this->admin, 'Arrival', 'film');

    $this->actingAs($this->admin)->from('/admin/types/film')
        ->put('/admin/types/film', [...$this->form, 'icon' => 'film', 'public' => '0', 'archive' => '0', 'searchable' => '0', 'comments' => ''])
        ->assertRedirect('/admin/types/film')
        ->assertSessionHasErrors(['public' => 'Films have items (1, trashed ones included), so whether they’re public can’t change.']);

    expect(app(ContentTypeRegistry::class)->get('film')->public)->toBeTrue();
});

it('deletes a type with no items, a refused one and one nobody can read too, and refuses one with items, saying how many', function (): void {
    tbSite($this->admin);
    DB::table(StoredTypes::TABLE)->insert(['key' => 'clip', 'definition' => json_encode(['segment' => 'clips', 'public' => 'yes']), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->admin)->delete('/admin/types/clip')
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/types')
        ->assertInertiaFlash('toast.message', 'Deleted clip.');
    $this->actingAs($this->admin)->delete('/admin/types/recipe')
        ->assertRedirect('/admin/types')
        ->assertInertiaFlash('toast.message', 'Deleted Recipes.');
    $this->actingAs($this->admin)->delete('/admin/types/event')
        ->assertRedirect('/admin/types')
        ->assertInertiaFlash('toast.message', 'Deleted Events.');
    $this->actingAs($this->admin)->from('/admin/types/film')->delete('/admin/types/film')
        ->assertRedirect('/admin/types/film')
        ->assertSessionHasErrors(['key' => 'The type Film has items (3, trashed ones included), so it can’t be deleted.']);

    expect(DB::table(StoredTypes::TABLE)->pluck('key')->all())->toBe(['film']);
});

it('downloads teme-types.json: the site’s own types, as teme:types:export writes them', function (): void {
    tbSite($this->admin);

    $response = $this->actingAs($this->admin)->get('/admin/types/teme-types.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertDownload('teme-types.json');
    $body = (string) $response->getContent();

    expect($body)->toBe(TypesFile::json())
        ->and(str_ends_with($body, "}\n"))->toBeTrue()
        ->and(array_column(json_decode($body, true)['types'], 'key'))->toBe(['film', 'recipe', 'event']);
});

it('is only for people who may manage types, and writes nothing for anyone else', function (): void {
    tbSite($this->admin);

    foreach (['/admin/types', '/admin/types/new-type', '/admin/types/film', '/admin/types/teme-types.json'] as $path) {
        $this->actingAs($this->editor)->get($path)->assertForbidden();
    }

    $this->actingAs($this->editor)->post('/admin/types', [...$this->form, 'key' => 'reel', 'segment' => 'reels'])->assertForbidden();
    $this->actingAs($this->editor)->put('/admin/types/recipe', [...$this->form, 'plural' => 'Dishes'])->assertForbidden();
    $this->actingAs($this->editor)->delete('/admin/types/recipe')->assertForbidden();

    expect(DB::table(StoredTypes::TABLE)->orderBy('key')->pluck('key')->all())->toBe(['event', 'film', 'recipe'])
        ->and(app(ContentTypeRegistry::class)->get('recipe')->plural)->toBe('Recipes');
});

it('shows everything and changes nothing while the types are locked', function (): void {
    tbSite($this->admin);
    config(['teme.types_locked' => true]);

    $this->actingAs($this->admin)->get('/admin/types')->assertInertia(fn (Assert $page) => $page->where('locked', true));
    $this->actingAs($this->admin)->get('/admin/types/film')->assertInertia(fn (Assert $page) => $page->where('mode', 'locked'));
    $this->actingAs($this->admin)->get('/admin/types/post')->assertInertia(fn (Assert $page) => $page->where('mode', 'view'));
    $this->actingAs($this->admin)->get('/admin/types/new-type')->assertRedirect('/admin/types');
    $this->actingAs($this->admin)->get('/admin/types/teme-types.json')->assertOk();

    $this->actingAs($this->admin)->post('/admin/types', [...$this->form, 'key' => 'reel', 'segment' => 'reels'])->assertForbidden();
    $this->actingAs($this->admin)->put('/admin/types/film', [...$this->form, 'icon' => 'film', 'plural' => 'Movies'])->assertForbidden();
    $this->actingAs($this->admin)->delete('/admin/types/recipe')->assertForbidden();

    expect(DB::table(StoredTypes::TABLE)->count())->toBe(3)
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Films');
});

it('links the Status banner for a saved type that can’t be used to Types', function (): void {
    tbSite($this->admin);

    expect(app(StoredTypesRefused::class)->banner()?->actionLabel)->toBe('Open Types')
        ->and(app(StoredTypesRefused::class)->banner()?->actionUrl)->toBe(route('types.index'));
});

it('puts Types in the Site section between Themes and People, for whoever may manage types', function (): void {
    $nav = fn (User $person): array => $this->actingAs($person)->get(route('dashboard'))->viewData('page')['props']['nav'];
    $site = array_values(array_filter($nav($this->admin), fn (array $entry): bool => $entry['section'] === 'site'));

    expect(array_column($site, 'key'))->toBe(['themes', 'plugins', 'types', 'taxonomies', 'users', 'tools', 'settings'])
        ->and($site[2])->toMatchArray(['label' => 'Types', 'icon' => 'shapes', 'href' => '/admin/types'])
        ->and(array_column($nav($this->editor), 'key'))->not->toContain('types');
});
