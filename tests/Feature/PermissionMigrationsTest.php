<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->catchUp = function (): void {
        $path = database_path('migrations/2026_10_08_090000_bring_permissions_to_existing_sites.php');

        expect($path)->toBeFile();
        (require $path)->up();
    };
});

it('creates every permission RoleSeeder grants through a migration, so none reaches fresh installs alone', function (): void {
    expect(Permission::query()->pluck('name')->all())->toEqualCanonicalizing(RoleSeeder::PERMISSIONS);
});

it('brings a site seeded before 1.6a to exactly the grants RoleSeeder makes today', function (): void {
    $added = ['settings.addresses.manage', 'settings.design.manage'];
    Permission::query()->whereIn('name', $added)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        Role::findOrCreate($role)->syncPermissions(array_values(array_diff($permissions, $added)));
    }

    ($this->catchUp)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }
});

it('keeps a grant an administrator made by hand, which re-running RoleSeeder would take away', function (): void {
    $this->seed(RoleSeeder::class);
    Role::findByName('editor')->givePermissionTo('settings.general.manage');

    ($this->catchUp)();

    expect(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue();
});

it('creates no role, since a new site gets its roles from a migration of their own', function (): void {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->catchUp)();

    expect(Role::query()->count())->toBe(0);
});
