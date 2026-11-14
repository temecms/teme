<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TypesFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->form = [
        'key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'icon' => 'clapperboard',
        'single' => '0', 'public' => '1', 'archive' => '1', 'searchable' => '1',
        'taxonomies' => ['category'], 'supports' => ['seo', 'excerpt'], 'comments' => 'closed', 'pattern' => '',
    ];
});

it('shows a type that shares the standard permissions with what each role may do with every type', function (): void {
    ctCreate($this->admin);

    $this->actingAs($this->admin)->get('/admin/types/film')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type.permissions', 'shared')
            ->where('permissions.roles', [
                ['key' => 'administrator', 'label' => 'Administrator'],
                ['key' => 'editor', 'label' => 'Editor'],
                ['key' => 'author', 'label' => 'Author'],
                ['key' => 'contributor', 'label' => 'Contributor'],
            ])
            ->where('permissions.actions', [
                ['key' => 'create', 'label' => 'Write new items', 'ability' => 'content.create'],
                ['key' => 'edit.own', 'label' => 'Edit their own', 'ability' => 'content.edit.own'],
                ['key' => 'edit.any', 'label' => 'Edit anyone’s', 'ability' => 'content.edit.any'],
                ['key' => 'publish', 'label' => 'Publish and schedule', 'ability' => 'content.publish'],
                ['key' => 'delete.own', 'label' => 'Delete their own', 'ability' => 'content.delete.own'],
                ['key' => 'delete.any', 'label' => 'Delete anyone’s', 'ability' => 'content.delete.any'],
            ])
            ->where('permissions.holds.administrator', ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any'])
            // An editor holds content.delete.any and not .own; "anyone’s" covers their own (I-3312).
            ->where('permissions.holds.editor', ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any'])
            ->where('permissions.holds.author', ['create', 'edit.own', 'publish', 'delete.own'])
            ->where('permissions.holds.contributor', ['create', 'edit.own', 'delete.own']));
});

it('shows a type with its own permissions as its roles hold them', function (): void {
    ctCreate($this->admin, ['permissions' => 'own']);
    Role::findByName('author')->revokePermissionTo('film.publish');

    $this->actingAs($this->admin)->get('/admin/types/film')
        ->assertInertia(fn (Assert $page) => $page
            ->where('type.permissions', 'own')
            ->where('permissions.actions.3', ['key' => 'publish', 'label' => 'Publish and schedule', 'ability' => 'film.publish'])
            ->where('permissions.holds.author', ['create', 'edit.own', 'delete.own'])
            ->where('permissions.holds.editor', ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any']));

    // Only the screen reads "anyone’s" as covering one's own: the grants stay as they are.
    expect(tpHeld('film')['editor'])->not->toContain('film.delete.own')
        ->and(collect(TypesFile::export()['grants'])->firstWhere('role', 'editor')['abilities'])->toBe(['create', 'edit.own', 'edit.any', 'publish', 'delete.any']);
});

it('lists a role made by hand after the four, by its name', function (): void {
    Role::findOrCreate('reviewer')->givePermissionTo('content.edit.any');
    ctCreate($this->admin);

    $this->actingAs($this->admin)->get('/admin/types/film')
        ->assertInertia(fn (Assert $page) => $page
            ->has('permissions.roles', 5)
            ->where('permissions.roles.4', ['key' => 'reviewer', 'label' => 'reviewer'])
            ->where('permissions.holds.reviewer', ['edit.own', 'edit.any']));
});

it('shows New type and a type code declares what each role may do with them', function (): void {
    $this->actingAs($this->admin)->get('/admin/types/new-type')
        ->assertInertia(fn (Assert $page) => $page
            ->where('type.permissions', 'shared')
            ->where('permissions.actions.0.ability', 'content.create')
            ->where('permissions.holds.contributor', ['create', 'edit.own', 'delete.own']));

    $this->actingAs($this->admin)->get('/admin/types/post')
        ->assertInertia(fn (Assert $page) => $page->where('type.permissions', 'shared'));

    $this->actingAs($this->admin)->get('/admin/types/region')
        ->assertInertia(fn (Assert $page) => $page
            ->where('type.permissions', 'own')
            ->where('permissions.actions.0.ability', 'regions.manage')
            ->where('permissions.holds.editor', ['create', 'edit.own', 'edit.any', 'publish', 'delete.own', 'delete.any'])
            ->where('permissions.holds.author', []));
});

it('gives a type its own permissions from its form, and takes them away again', function (): void {
    ctCreate($this->admin);

    $this->actingAs($this->admin)->put('/admin/types/film', [...$this->form, 'permissions' => 'own'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/types/film')
        ->assertInertiaFlash('toast.message', 'Saved Films.');

    expect(app(ContentTypeRegistry::class)->get('film')->ability('create'))->toBe('film.create')
        ->and(tpHeld('film'))->toBe(tpStarting());

    $this->actingAs($this->admin)->put('/admin/types/film', [...$this->form, 'permissions' => 'shared'])->assertSessionHasNoErrors();

    expect(app(ContentTypeRegistry::class)->get('film')->ability('create'))->toBe('content.create')
        ->and(Permission::query()->where('name', 'like', 'film.%')->count())->toBe(0);
});

it('makes a type with its own permissions from New type', function (): void {
    $this->actingAs($this->admin)->post('/admin/types', [...$this->form, 'permissions' => 'own'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/types/film');

    expect(tpHeld('film'))->toBe(tpStarting());
});

it('reads anything but own from the form as the standard permissions', function (): void {
    $this->actingAs($this->admin)->post('/admin/types', [...$this->form, 'permissions' => 'everyone'])->assertSessionHasNoErrors();

    expect(app(ContentTypeRegistry::class)->get('film')->ability('create'))->toBe('content.create')
        ->and(Permission::query()->where('name', 'like', 'film.%')->count())->toBe(0);
});

it('says beside the choice why a type can’t have its own permissions', function (): void {
    ctCreate($this->admin);
    Permission::findOrCreate('film.publish');

    $this->actingAs($this->admin)->put('/admin/types/film', [...$this->form, 'permissions' => 'own'])
        ->assertSessionHasErrors(['permissions' => 'A permission named film.publish already exists, so Films can’t have permissions of their own.']);
});
