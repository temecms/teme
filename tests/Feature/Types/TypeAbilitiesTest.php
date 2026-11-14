<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TypePermissions;
use App\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ctCreate($this->admin, ['permissions' => 'own']);
    // A role that writes films and nothing else.
    $this->critic = User::factory()->create()->assignRole(Role::findOrCreate('critic')->givePermissionTo(['film.create', 'film.edit.own']));
    // Another process gives Recipes their own permissions: the rows, the types' copy, the forgotten list.
    $this->recipesMadeOwnElsewhere = function (): void {
        foreach (TypePermissions::names('recipe') as $name) {
            DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        ctStoreRow(ctDefinition(['key' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes', 'segment' => 'recipes', 'permissions' => 'own']));
        Cache::forget((string) config('permission.cache.key'));
    };
});

it('opens a type’s list to someone who holds its own create permission and not the shared one, and lets them start an item there', function (): void {
    $this->actingAs($this->critic)->get('/admin/films')->assertOk();
    $this->actingAs($this->critic)->post('/admin/films', ['title' => 'Arrival'])->assertRedirect();

    expect(Content::query()->where('type', 'film')->count())->toBe(1);
});

it('refuses a type’s list to someone who holds the shared create permission and not the type’s own', function (): void {
    Role::findByName('contributor')->revokePermissionTo('film.create');
    $contributor = User::factory()->create()->assignRole('contributor');

    $this->actingAs($contributor)->get('/admin/films')->assertForbidden();
    $this->actingAs($contributor)->post('/admin/films', ['title' => 'Arrival'])->assertForbidden();
    $this->actingAs($contributor)->get('/admin/posts')->assertOk();
});

it('judges a segment no type has by the shared create permission, so it stays a 403 or a 404 as before', function (): void {
    $this->actingAs($this->critic)->get('/admin/reels')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/reels')->assertNotFound();
});

it('puts the type in the menu of whoever holds its own create permission, and only theirs', function (): void {
    Role::findByName('contributor')->revokePermissionTo('film.create');
    $contributor = User::factory()->create()->assignRole('contributor');

    $critics = array_column($this->actingAs($this->critic)->get(route('dashboard'))->viewData('page')['props']['nav'], 'key');
    $contributors = array_column($this->actingAs($contributor)->get(route('dashboard'))->viewData('page')['props']['nav'], 'key');

    expect($critics)->toContain('films')->not->toContain('posts')
        ->and($contributors)->toContain('posts')->not->toContain('films');
});

it('lets a queue worker see the permissions another process gave a type, from its next job', function (): void {
    $registrar = app(PermissionRegistrar::class);
    expect($registrar->getPermissions(['name' => 'film.publish']))->toHaveCount(1);

    ($this->recipesMadeOwnElsewhere)();

    expect($registrar->getPermissions(['name' => 'recipe.publish']))->toHaveCount(0);

    event(new JobProcessing('database', new SyncJob(app(), '{}', 'database', 'default')));

    expect(app(ContentTypeRegistry::class)->get('recipe')->ability('publish'))->toBe('recipe.publish')
        ->and($registrar->getPermissions(['name' => 'recipe.publish']))->toHaveCount(1)
        ->and(User::permission('recipe.publish')->count())->toBe(0);
});

it('lets a long-lived web process see them after the request it serves', function (): void {
    $registrar = app(PermissionRegistrar::class);
    expect($registrar->getPermissions(['name' => 'film.publish']))->toHaveCount(1);

    ($this->recipesMadeOwnElsewhere)();
    event(new RequestHandled(Request::create('/'), new Response));

    expect($registrar->getPermissions(['name' => 'recipe.publish']))->toHaveCount(1);
});
