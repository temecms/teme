<?php

declare(strict_types=1);

use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\CreateType;
use App\Domain\Types\Operations\DeleteType;
use App\Domain\Types\Operations\Input\CreateTypeInput;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Domain\Types\StoredTypes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->note = ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null];
    $this->update = fn (array $overrides, ?User $actor = null): array => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition($overrides), (string) ($actor ?? $this->admin)->id));
    $this->delete = fn (string $key, ?User $actor = null): array => app(DeleteType::class)->execute(new DeleteTypeInput($key, (string) ($actor ?? $this->admin)->id));
});

it('makes the type an administrator asks for, live in this process at once', function (): void {
    expect(ctCreate($this->admin))->toBe(['key' => 'film']);

    $row = DB::table('content_type_definitions')->where('key', 'film')->sole();

    expect(json_decode($row->definition, true))->toEqual([
        'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'film', 'single' => false,
        'public' => true, 'archive' => true, 'searchable' => true,
        'taxonomies' => ['category'], 'supports' => ['seo', 'excerpt', 'featured_image'],
        'comments' => 'closed', 'pattern' => '/films/{slug}/', 'permissions' => 'shared',
    ])
        ->and(app(ContentTypeRegistry::class)->get('film')->stored)->toBeTrue()
        ->and(app(ContentTypeRegistry::class)->bySegment('films')?->plural)->toBe('Films')
        ->and(app(SettingsRegistry::class)->find('addresses')?->setting('pattern_film')?->default)->toBe('/films/{slug}/');
});

it('keeps the address pattern it is given, and gives a type that isn’t public none', function (): void {
    ctCreate($this->admin, ['pattern' => '/watch/{slug}/']);
    ctCreate($this->admin, $this->note);

    expect(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/watch/{slug}/')
        ->and(app(ContentTypeRegistry::class)->get('note')->pattern)->toBeNull()
        ->and(app(ContentTypeRegistry::class)->get('note')->public)->toBeFalse();
});

it('refuses a type it can’t make, naming the field, and writes nothing', function (array $overrides, string $field, string $message): void {
    expect(fn () => ctCreate($this->admin, $overrides))->toThrow(function (ValidationException $refusal) use ($field, $message): void {
        expect($refusal->errors()[$field] ?? [])->toContain($message);
    });

    expect(DB::table('content_type_definitions')->count())->toBe(0);
})->with([
    'a key with capitals' => [['key' => 'Film'], 'key', 'A key is lower-case letters, digits and underscores, starts with a letter, and is 32 characters at most.'],
    'a key code has' => [['key' => 'post'], 'key', 'There’s already a type with the key post.'],
    'a key that names permissions' => [['key' => 'media'], 'key', 'The key media names a group of permissions, so a type can’t have it.'],
    'no singular name' => [['singular' => ' '], 'singular', 'Give the type a singular name of 60 characters at most.'],
    'a segment with a slash' => [['segment' => 'films/new'], 'segment', 'An admin address is lower-case letters, digits and hyphens, starts with a letter, and is 40 characters at most.'],
    'a segment of the admin’s own' => [['segment' => 'media'], 'segment', '/admin/media is one of the admin’s own screens.'],
    'a segment a taxonomy has' => [['segment' => 'categories'], 'segment', '/admin/categories already shows Categories.'],
    'a segment a type has' => [['segment' => 'posts'], 'segment', '/admin/posts already shows Posts.'],
    'an icon that isn’t a name' => [['icon' => 'Film Reel'], 'icon', 'An icon is the name of a Lucide icon: lower-case letters, digits and hyphens.'],
    'a taxonomy nobody registered' => [['taxonomies' => ['genre']], 'taxonomies', 'There is no taxonomy genre.'],
    'a feature nothing provides' => [['supports' => ['ratings']], 'supports', 'Nothing on this site provides ratings.'],
    'comments on a type that isn’t public' => [['public' => false, 'archive' => false, 'searchable' => false, 'comments' => 'open'], 'comments', 'Only a public type’s items can take comments: they’re shown on each item’s own page.'],
    'an archive for a type that isn’t public' => [['public' => false, 'searchable' => false, 'comments' => null], 'archive', 'Only a public type can have an archive.'],
    'search for a type that isn’t public' => [['public' => false, 'archive' => false, 'comments' => null], 'searchable', 'Only a public type can be searched: search results link to each item’s own page.'],
    'a pattern for a type that isn’t public' => [['public' => false, 'archive' => false, 'searchable' => false, 'comments' => null, 'pattern' => '/notes/{slug}/'], 'pattern', 'A type that isn’t public has no address pattern.'],
    'a pattern the site’s own addresses start' => [['pattern' => '/feed/{slug}/'], 'pattern', 'Addresses starting /feed/ are used by the site itself.'],
    'a segment whose default address is the built files’' => [['segment' => 'build'], 'segment', 'Addresses starting /build/ are used by the site itself.'],
    'a segment whose default address is storage’s' => [['segment' => 'storage'], 'segment', 'Addresses starting /storage/ are used by the site itself.'],
]);

it('writes nothing when authorization fails to make a type', function (): void {
    expect(fn () => ctCreate($this->editor))->toThrow(AuthorizationException::class, 'Not permitted to change the types.');

    expect(DB::table('content_type_definitions')->count())->toBe(0)
        ->and(app(ContentTypeRegistry::class)->has('film'))->toBeFalse();
});

it('refuses a change with no person behind it unless the operator makes it', function (): void {
    expect(fn () => app(CreateType::class)->execute(new CreateTypeInput(ctDefinition(), null)))->toThrow(AuthorizationException::class);

    expect(DB::table('content_type_definitions')->count())->toBe(0);

    ctCreate(null);

    expect(app(ContentTypeRegistry::class)->has('film'))->toBeTrue();
});

it('refuses every person while the types are locked, and lets the operator in', function (): void {
    config(['teme.types_locked' => true]);

    expect(fn () => ctCreate($this->admin))->toThrow(AuthorizationException::class, 'Types are locked on this site (TEME_TYPES_LOCKED)');
    expect(DB::table('content_type_definitions')->count())->toBe(0);

    ctCreate(null);

    expect(app(ContentTypeRegistry::class)->has('film'))->toBeTrue();
});

it('changes a type’s names, admin address and icon while it has items, and its items keep their addresses', function (): void {
    ctCreate($this->admin);
    $item = addrPublished($this->admin, 'Arrival', 'film');

    expect(($this->update)(['singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'icon' => 'clapperboard']))->toBe(['key' => 'film', 'changed' => true]);

    $this->actingAs($this->admin)->get('/admin/movies')->assertOk()->assertInertia(fn (Assert $page) => $page->where('labels.plural', 'Movies'));
    $this->actingAs($this->admin)->get('/admin/films')->assertNotFound();

    expect(addrCurrent('content', $item->id))->toBe('films/arrival')
        ->and(app(ContentTypeRegistry::class)->get('film')->icon)->toBe('clapperboard');
});

it('keeps the address pattern a type was made with, whatever a change says', function (): void {
    ctCreate($this->admin);
    ($this->update)(['pattern' => '/watch/{slug}/']);

    expect(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/films/{slug}/');
});

it('gives a type that becomes public the address pattern the change gives, the one its checks read', function (): void {
    ctCreate($this->admin, ['segment' => 'build', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null]);
    ($this->update)(['segment' => 'build', 'pattern' => '/films/{slug}/']);

    expect(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/films/{slug}/');
});

it('says when a change changes nothing', function (): void {
    ctCreate($this->admin);

    expect(($this->update)([]))->toBe(['key' => 'film', 'changed' => false]);
});

it('refuses to change a type code declares, or one that doesn’t exist', function (string $key, string $message): void {
    expect(fn () => ($this->update)(['key' => $key]))->toThrow(function (ValidationException $refusal) use ($message): void {
        expect($refusal->errors()['key'])->toContain($message);
    });
})->with([
    'code’s' => ['post', 'Posts are declared by code, so they can’t be changed or deleted here.'],
    'nobody’s' => ['film', 'There is no type film.'],
]);

it('refuses to change whether a type is public while it has items, trashed ones included', function (): void {
    ctCreate($this->admin);
    pageTrash(lifecycleDraft($this->admin, 'Arrival', 'film'), $this->admin);

    expect(fn () => ($this->update)(['public' => false, 'archive' => false, 'searchable' => false, 'comments' => null]))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['public'])->toContain('Films have items (1, trashed ones included), so whether they’re public can’t change.');
    });

    expect(app(ContentTypeRegistry::class)->get('film')->public)->toBeTrue();
});

it('lets a type with no items stop being public, and takes its address pattern away', function (): void {
    ctCreate($this->admin);
    ($this->update)(['public' => false, 'archive' => false, 'searchable' => false, 'comments' => null]);

    expect(app(ContentTypeRegistry::class)->get('film')->public)->toBeFalse()
        ->and(app(ContentTypeRegistry::class)->get('film')->pattern)->toBeNull();
});

it('refuses to take a taxonomy from a type whose items have terms in it, and allows it when none do', function (): void {
    ctCreate($this->admin);
    typesDraft($this->admin, 'film', 'Arrival', [taxonomyTerm('category', 'Science fiction')->id]);

    expect(fn () => ($this->update)(['taxonomies' => []]))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['taxonomies'])->toContain('Films still use Categories: take those terms off them first.');
    });

    $reel = ['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'reels'];
    ctCreate($this->admin, $reel);
    typesDraft($this->admin, 'reel', 'Untagged');
    ($this->update)([...$reel, 'taxonomies' => []]);

    expect(app(ContentTypeRegistry::class)->get('reel')->taxonomies)->toBe([])
        ->and(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['category']);
});

it('writes nothing when authorization fails to change a type', function (): void {
    ctCreate($this->admin);
    $before = DB::table('content_type_definitions')->value('definition');

    expect(fn () => ($this->update)(['plural' => 'Movies'], $this->editor))->toThrow(AuthorizationException::class);

    expect(DB::table('content_type_definitions')->value('definition'))->toBe($before)
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Films');
});

it('deletes a type with no items, and its screens and settings go with it', function (): void {
    ctCreate($this->admin);

    expect(($this->delete)('film'))->toBe(['key' => 'film']);

    expect(DB::table('content_type_definitions')->count())->toBe(0)
        ->and(app(ContentTypeRegistry::class)->has('film'))->toBeFalse()
        ->and(app(SettingsRegistry::class)->find('addresses')?->setting('pattern_film'))->toBeNull();
    $this->actingAs($this->admin)->get('/admin/films')->assertNotFound();
});

it('refuses to delete a type that has items, trashed ones included', function (): void {
    ctCreate($this->admin);
    pageTrash(lifecycleDraft($this->admin, 'Arrival', 'film'), $this->admin);

    expect(fn () => ($this->delete)('film'))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['key'])->toContain('The type Film has items (1, trashed ones included), so it can’t be deleted.');
    });

    expect(app(ContentTypeRegistry::class)->has('film'))->toBeTrue();
});

it('refuses to delete a type code declares, or one that doesn’t exist', function (string $key, string $message): void {
    expect(fn () => ($this->delete)($key))->toThrow(function (ValidationException $refusal) use ($message): void {
        expect($refusal->errors()['key'])->toContain($message);
    });
})->with([
    'code’s' => ['page', 'Pages are declared by code, so they can’t be changed or deleted here.'],
    'nobody’s' => ['film', 'There is no type film.'],
]);

it('writes nothing when authorization fails to delete a type', function (): void {
    ctCreate($this->admin);

    expect(fn () => ($this->delete)('film', $this->editor))->toThrow(AuthorizationException::class);

    expect(DB::table('content_type_definitions')->count())->toBe(1);
});

it('mends a saved type it can’t read from the change a person makes, keeping the address pattern it could read', function (): void {
    DB::table('content_type_definitions')->insert(['key' => 'film', 'definition' => json_encode(['segment' => 'films', 'public' => 'yes', 'pattern' => '/watch/{slug}/']), 'created_at' => now(), 'updated_at' => now()]);
    app(StoredTypes::class)->remember();
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(ContentTypeRegistry::class)->refused())->toHaveKey('film')
        ->and(($this->update)([]))->toBe(['key' => 'film', 'changed' => true])
        ->and(app(ContentTypeRegistry::class)->refused())->toBe([])
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Films')
        ->and(app(ContentTypeRegistry::class)->get('film')->pattern)->toBe('/watch/{slug}/');
});

it('makes a type one item only while it has one item, and refuses it with more, trashed ones counted', function (): void {
    ctCreate($this->admin);
    lifecycleDraft($this->admin, 'Arrival', 'film');

    expect(($this->update)(['single' => true]))->toBe(['key' => 'film', 'changed' => true])
        ->and(app(ContentTypeRegistry::class)->get('film')->single)->toBeTrue();

    ($this->update)(['single' => false]);
    pageTrash(lifecycleDraft($this->admin, 'Heat', 'film'), $this->admin);

    expect(fn () => ($this->update)(['single' => true]))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['single'])->toContain('Films have 2 items, so they can’t become one item only.');
    });

    expect(app(ContentTypeRegistry::class)->get('film')->single)->toBeFalse();
});

it('still reads a person’s preferences in a process that built them before a type was deleted', function (): void {
    ctCreate($this->admin);

    expect(app(PreferenceRegistry::class)->all())->toHaveKey('lists.film.columns');

    ($this->delete)('film');

    expect(fn () => PreferencesFor::user($this->admin->refresh()))->not->toThrow(Throwable::class)
        ->and(PreferencesFor::user($this->admin->refresh()))->toHaveKey('lists.post.columns');
});

it('empties the page cache after a change commits, and not after one that rolls back', function (): void {
    $folder = cacheOn();
    ctCreate($this->admin);
    cachePage('/about/');

    cacheRolledBack(fn () => ($this->update)(['plural' => 'Movies']));

    expect(cacheKept('/about/'))->toBe(['/about/'])
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Films');

    ($this->update)(['plural' => 'Movies']);

    expect(cacheKept('/about/'))->toBe([]);

    cacheOff($folder);
});

it('still empties the page cache, and reports the failure, when the cache store refuses the types’ copy', function (): void {
    $folder = cacheOn();
    ctCreate($this->admin);
    cachePage('/about/');
    Exceptions::fake();
    // Readers add() the copy; only the after-commit put() is refused.
    Cache::extend('refusing', fn () => Cache::repository(new class extends ArrayStore
    {
        public function add(string $key, mixed $value, int $seconds): bool
        {
            return $this->get($key) === null && parent::put($key, $value, $seconds);
        }

        public function put($key, $value, $seconds): bool
        {
            throw new RuntimeException('The cache store is down.');
        }
    }));
    config(['cache.stores.refusing' => ['driver' => 'refusing'], 'cache.default' => 'refusing']);

    ($this->update)(['plural' => 'Movies']);

    Exceptions::assertReported(RuntimeException::class);
    expect(cacheKept('/about/'))->toBe([])
        ->and(json_decode((string) DB::table('content_type_definitions')->where('key', 'film')->value('definition'), true)['plural'])->toBe('Movies');

    cacheOff($folder);
});
