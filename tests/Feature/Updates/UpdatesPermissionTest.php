<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A site as it stood before updates.manage existed: RoleSeeder's grants, less it.
    $this->seed(RoleSeeder::class);
    Permission::query()->where('name', 'updates.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2027_03_01_100200_add_updates_permission.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('brings updates.manage to administrators alone, on a site seeded before it existed (I-449)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    expect(Role::findByName('administrator')->hasPermissionTo('updates.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('updates.manage'))->toBeFalse()
        ->and(Role::findByName('author')->hasPermissionTo('updates.manage'))->toBeFalse()
        ->and(Role::findByName('contributor')->hasPermissionTo('updates.manage'))->toBeFalse();
});

it('only adds: a grant made by hand stays, a missing role isn\'t made, and a second run changes nothing', function (): void {
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    Role::findByName('administrator')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::query()->where('name', 'administrator')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'updates.manage')->count())->toBe(1);
});

it('gives updates.manage to administrators and nobody else on a fresh site', function (): void {
    $this->seed(RoleSeeder::class);

    expect(RoleSeeder::PERMISSIONS)->toContain('updates.manage')
        ->and(User::factory()->create()->assignRole('administrator')->can('updates.manage'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('editor')->can('updates.manage'))->toBeFalse();
});
