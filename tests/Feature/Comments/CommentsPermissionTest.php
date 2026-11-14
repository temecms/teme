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
        $path = database_path('migrations/2026_10_18_100200_add_comments_moderate_permission.php');

        expect($path)->toBeFile();
        (require $path)->up();
    };
});

it('brings a site seeded before 1.8a to exactly the grants RoleSeeder makes today (I-449)', function (): void {
    Permission::query()->where('name', 'comments.moderate')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        Role::findOrCreate($role)->syncPermissions(array_values(array_diff($permissions, ['comments.moderate'])));
    }

    ($this->grant)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    expect(Role::findByName('editor')->hasPermissionTo('comments.moderate'))->toBeTrue()
        ->and(Role::findByName('author')->hasPermissionTo('comments.moderate'))->toBeFalse();
});

it('keeps a grant an administrator made by hand, which re-running RoleSeeder would take away', function (): void {
    $this->seed(RoleSeeder::class);
    Role::findByName('author')->givePermissionTo('settings.general.manage');

    ($this->grant)();

    expect(Role::findByName('author')->hasPermissionTo('settings.general.manage'))->toBeTrue();
});

it('creates no role, since a new site gets its roles from a migration of their own', function (): void {
    Role::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->grant)();

    expect(Role::query()->count())->toBe(0);
});
