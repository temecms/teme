<?php

declare(strict_types=1);

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\Queries\CommentRecipients;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\DeleteType;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Domain\Types\TypeDefinition;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->update = fn (array $overrides, ?User $actor = null): array => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition($overrides), (string) ($actor ?? $this->admin)->id));
    $this->delete = fn (string $key, ?User $actor = null): array => app(DeleteType::class)->execute(new DeleteTypeInput($key, (string) ($actor ?? $this->admin)->id));
    $this->stored = fn (): array => json_decode((string) DB::table('content_type_definitions')->where('key', 'film')->value('definition'), true);
    $this->filmPermissions = fn (): array => Permission::query()->where('name', 'like', 'film.%')->pluck('name')->sort()->values()->all();
});

it('names a type’s own six abilities when it has its own, and none when it shares the standard ones', function (): void {
    expect(ctDefinition(['permissions' => 'own'])->toContentType()->abilities)->toBe([
        'create' => 'film.create', 'edit.own' => 'film.edit.own', 'edit.any' => 'film.edit.any',
        'publish' => 'film.publish', 'delete.own' => 'film.delete.own', 'delete.any' => 'film.delete.any',
    ])
        ->and(ctDefinition()->toContentType()->abilities)->toBe([])
        ->and(ctDefinition()->toContentType()->ability('publish'))->toBe('content.publish')
        ->and(TypeDefinition::fromArray(['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films'])->permissions)->toBe('shared');
});

it('gives a type made with its own permissions its six, each role starting with what it may do with every type', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);

    expect(($this->filmPermissions)())->toBe(['film.create', 'film.delete.any', 'film.delete.own', 'film.edit.any', 'film.edit.own', 'film.publish'])
        ->and(tpHeld('film'))->toBe(tpStarting())
        ->and(($this->stored)()['permissions'])->toBe('own')
        ->and(app(ContentTypeRegistry::class)->get('film')->ability('publish'))->toBe('film.publish');
});

it('makes no permission for a type that uses the standard ones', function (): void {
    $before = Permission::query()->count();

    ctCreate($this->admin);

    expect(Permission::query()->count())->toBe($before)
        ->and(($this->stored)()['permissions'])->toBe('shared');
});

it('starts each role from what it holds on this site now, a role made by hand included', function (): void {
    Role::findByName('editor')->givePermissionTo('content.delete.own');
    Role::findByName('author')->revokePermissionTo('content.publish');
    Role::findOrCreate('reviewer')->givePermissionTo('content.edit.any');

    ctCreate($this->admin, ['permissions' => 'own']);

    expect(tpHeld('film')['editor'])->toBe(['film.create', 'film.delete.any', 'film.delete.own', 'film.edit.any', 'film.edit.own', 'film.publish'])
        ->and(tpHeld('film')['author'])->toBe(['film.create', 'film.delete.own', 'film.edit.own'])
        ->and(Role::findByName('reviewer')->permissions()->pluck('name')->sort()->values()->all())->toBe(['content.edit.any', 'film.edit.any']);
});

it('lets each role do with its items what the type’s own permissions say, whatever the shared ones say', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    $film = lifecycleDraft($this->author, 'Arrival', 'film');
    $post = lifecycleDraft($this->author, 'Harbour news');

    Role::findByName('author')->revokePermissionTo('film.publish');

    expect(Gate::forUser($this->author)->allows('publish', $film))->toBeFalse()
        ->and(Gate::forUser($this->author)->allows('update', $film))->toBeTrue()
        ->and(Gate::forUser($this->author)->allows('publish', $post))->toBeTrue();

    Role::findByName('author')->givePermissionTo('film.publish');
    Role::findByName('author')->revokePermissionTo('content.publish');

    expect(Gate::forUser($this->author)->allows('publish', $film))->toBeTrue()
        ->and(Gate::forUser($this->author)->allows('publish', $post))->toBeFalse();
});

it('gives a type with items its own permissions, and nobody can do less with them than before', function (): void {
    ctCreate($this->admin);
    $film = lifecycleDraft($this->contributor, 'Arrival', 'film');

    expect(($this->update)(['permissions' => 'own']))->toBe(['key' => 'film', 'changed' => true])
        ->and(tpHeld('film'))->toBe(tpStarting())
        ->and(Gate::forUser($this->contributor)->allows('update', $film))->toBeTrue()
        ->and(Gate::forUser($this->contributor)->allows('publish', $film))->toBeFalse()
        ->and(Gate::forUser($this->editor)->allows('publish', $film))->toBeTrue();
});

it('takes a type back to the standard permissions, removing its six and every grant of them', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    $this->editor->givePermissionTo('film.publish');

    ($this->update)(['permissions' => 'shared']);

    expect(($this->filmPermissions)())->toBe([])
        ->and(DB::table('model_has_permissions')->count())->toBe(0)
        ->and(tpHeld('film'))->toBe(['administrator' => [], 'editor' => [], 'author' => [], 'contributor' => []])
        ->and(app(ContentTypeRegistry::class)->get('film')->ability('publish'))->toBe('content.publish')
        ->and(($this->stored)()['permissions'])->toBe('shared');
});

it('removes a type’s own permissions with it, and nothing else', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    $before = Permission::query()->count();

    ($this->delete)('film');

    expect(Permission::query()->count())->toBe($before - 6)
        ->and(($this->filmPermissions)())->toBe([])
        ->and(Role::findByName('editor')->permissions()->pluck('name')->all())->toEqualCanonicalizing(RoleSeeder::ROLES['editor']);
});

it('leaves a permission with one of the names alone when a type that never had its own is deleted', function (): void {
    ctCreate($this->admin);
    Permission::findOrCreate('film.create');

    ($this->delete)('film');

    expect(($this->filmPermissions)())->toBe(['film.create']);
});

it('refuses to give a type its own permissions while one of their names is taken, and writes nothing', function (): void {
    ctCreate($this->admin);
    Permission::findOrCreate('film.publish');

    expect(fn () => ($this->update)(['permissions' => 'own']))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors())->toBe(['permissions' => ['A permission named film.publish already exists, so Films can’t have permissions of their own.']]);
    });

    expect(($this->filmPermissions)())->toBe(['film.publish'])
        ->and(($this->stored)()['permissions'])->toBe('shared')
        ->and(app(ContentTypeRegistry::class)->get('film')->ability('publish'))->toBe('content.publish');
});

it('writes nothing when authorization fails to make a type with its own permissions', function (): void {
    expect(fn () => ctCreate($this->editor, ['permissions' => 'own']))->toThrow(AuthorizationException::class);

    expect(($this->filmPermissions)())->toBe([])
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
});

it('writes nothing when authorization fails to give a type its own permissions', function (): void {
    ctCreate($this->admin);

    expect(fn () => ($this->update)(['permissions' => 'own'], $this->editor))->toThrow(AuthorizationException::class);

    expect(($this->filmPermissions)())->toBe([])
        ->and(($this->stored)()['permissions'])->toBe('shared');
});

it('writes nothing when authorization fails to delete a type with its own permissions', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);

    expect(fn () => ($this->delete)('film', $this->editor))->toThrow(AuthorizationException::class);

    expect(($this->filmPermissions)())->toHaveCount(6)
        ->and(tpHeld('film'))->toBe(tpStarting());
});

it('writes nothing when authorization fails to import a type with its own permissions and grants', function (): void {
    $file = ctFile([ctDefinition(['permissions' => 'own'])->toArray()], ['grants' => [['type' => 'film', 'role' => 'author', 'abilities' => ['create']]]]);

    expect(fn () => app(ImportTypes::class)->execute(new ImportTypesInput($file, (string) $this->editor->id)))->toThrow(AuthorizationException::class);

    expect(($this->filmPermissions)())->toBe([])
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
});

it('still finds who looks after an item’s comments when the type’s own permissions are missing', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    $film = lifecycleDraft($this->author, 'Arrival', 'film');
    // Removed by hand, behind the types' back: no row has these names now.
    DB::table('permissions')->whereIn('name', ['film.publish', 'film.edit.any'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $moderators = fn (): array => app(CommentRecipients::class)->moderators($film, [], CommentEmails::WAITING);

    expect($moderators)->not->toThrow(Throwable::class)
        ->and(array_map(fn (User $person): int => (int) $person->id, $moderators()))->toBe([(int) $this->admin->id, (int) $this->editor->id]);
});

it('still finds who looks after an item’s comments while a worker holds a list read before the type got its own', function (): void {
    ctCreate($this->admin);
    $film = lifecycleDraft($this->author, 'Arrival', 'film');
    $key = (string) config('permission.cache.key');
    $registrar = app(PermissionRegistrar::class);
    $registrar->getPermissions();
    $before = Cache::get($key);

    ($this->update)(['permissions' => 'own']);
    // Another process read the list before the change committed, and wrote it after the forget: the six are
    // in the database, but not in the list Spatie reads.
    Cache::forever($key, $before);
    $registrar->clearPermissionsCollection();

    $moderators = fn (): array => app(CommentRecipients::class)->moderators($film, [], CommentEmails::WAITING);

    expect($moderators)->not->toThrow(Throwable::class)
        ->and(array_map(fn (User $person): int => (int) $person->id, $moderators()))->toBe([(int) $this->admin->id, (int) $this->editor->id]);
});

it('forgets the cached permissions once the change commits, so a list cached meanwhile is read again', function (): void {
    ctCreate($this->admin);
    $key = (string) config('permission.cache.key');

    DB::transaction(function () use ($key): void {
        ($this->update)(['permissions' => 'own']);
        // Another process reads the permissions before this commits, and caches the list without Films’.
        Cache::forever($key, 'the list before the change');
    });

    expect(Cache::has($key))->toBeFalse()
        ->and($this->author->fresh()?->can('film.create'))->toBeTrue();
});

it('leaves the cached permissions alone when the change rolls back', function (): void {
    ctCreate($this->admin);
    $key = (string) config('permission.cache.key');

    cacheRolledBack(function () use ($key): void {
        ($this->update)(['permissions' => 'own']);
        Cache::forever($key, 'cached by another process');
    });

    expect(Cache::get($key))->toBe('cached by another process')
        ->and(($this->filmPermissions)())->toBe([])
        ->and(app(ContentTypeRegistry::class)->get('film')->ability('publish'))->toBe('content.publish');

    Cache::forget($key);
});
