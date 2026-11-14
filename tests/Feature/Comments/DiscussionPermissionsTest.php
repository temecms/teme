<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->added = ['settings.discussion.manage', 'settings.modules.manage'];

    // A site as it stood before 1.8d: RoleSeeder's grants, less this part's
    // two permissions, which didn't exist.
    $this->seed(RoleSeeder::class);
    Permission::query()->whereIn('name', $this->added)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        (require database_path('migrations/2026_10_23_100000_add_discussion_settings_permissions.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('gives administrators both permissions on a site seeded before 1.8d, and no other role either (I-449, I-685)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    expect(Role::findByName('administrator')->hasPermissionTo('settings.modules.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('settings.discussion.manage'))->toBeFalse();
});

it('only adds: a grant made by hand stays, a missing role isn\'t made, and a second run changes nothing (I-449)', function (): void {
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    Role::findByName('administrator')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::query()->where('name', 'administrator')->exists())->toBeFalse()
        ->and(Permission::query()->whereIn('name', $this->added)->count())->toBe(2);
});
