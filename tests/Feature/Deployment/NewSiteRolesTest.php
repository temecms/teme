<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->addRoles = function (): void {
        $path = database_path('migrations/2027_01_26_100100_add_roles_to_new_sites.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('has made Teme\'s four roles on every migrated database, as a new site\'s first deploy does (I-449)', function (): void {
    expect(Role::query()->orderBy('name')->pluck('name')->all())->toBe(['administrator', 'author', 'contributor', 'editor']);
});

it('gives a new site’s editors and administrators pages’ six by migrating alone, and authors and contributors none (I-3313)', function (): void {
    $pages = fn (string $role): array => Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'page.'))->sort()->values()->all();

    expect($pages('administrator'))->toBe(['page.create', 'page.delete.any', 'page.delete.own', 'page.edit.any', 'page.edit.own', 'page.publish'])
        ->and($pages('editor'))->toBe(['page.create', 'page.delete.any', 'page.edit.any', 'page.edit.own', 'page.publish'])
        ->and($pages('author'))->toBe([])
        ->and($pages('contributor'))->toBe([]);
});

it('gives a site with no role the four, each with the permissions RoleSeeder gives it', function (): void {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ($this->addRoles)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }
});

it('changes nothing on a site with roles: a role an administrator deleted stays deleted, and a grant they made stays', function (): void {
    $this->seed(RoleSeeder::class);
    Role::findByName('contributor')->delete();
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ($this->addRoles)();

    expect(Role::query()->pluck('name')->all())->not->toContain('contributor')
        ->and(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue();
});
