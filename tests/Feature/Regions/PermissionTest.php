<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->grant = function (): void {
        $path = database_path('migrations/2026_10_09_200000_add_regions_manage_permission.php');

        expect($path)->toBeFile();
        (require $path)->up();
    };
});

it('brings a site seeded before 1.6e to exactly the grants RoleSeeder makes today', function (): void {
    Permission::query()->where('name', 'regions.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        Role::findOrCreate($role)->syncPermissions(array_values(array_diff($permissions, ['regions.manage'])));
    }

    ($this->grant)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }
});

it('keeps a grant an administrator made by hand, which re-running RoleSeeder would take away', function (): void {
    $this->seed(RoleSeeder::class);
    Role::findByName('editor')->givePermissionTo('settings.general.manage');

    ($this->grant)();

    expect(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('regions.manage'))->toBeTrue();
});

it('creates no role, since a new site gets its roles from a migration of their own', function (): void {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->grant)();

    expect(Role::query()->count())->toBe(0);
});
