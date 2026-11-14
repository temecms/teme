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
    // A site as it stood before backups.manage existed: RoleSeeder's grants, less it.
    $this->seed(RoleSeeder::class);
    Permission::query()->where('name', 'backups.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2027_01_05_100300_add_backups_permission.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('brings backups.manage to administrators alone, on a site seeded before it existed (I-449)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    expect(Role::findByName('administrator')->hasPermissionTo('backups.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('backups.manage'))->toBeFalse()
        ->and(Role::findByName('author')->hasPermissionTo('backups.manage'))->toBeFalse()
        ->and(Role::findByName('contributor')->hasPermissionTo('backups.manage'))->toBeFalse();
});

it('only adds: a grant made by hand stays, a missing role isn\'t made, and a second run changes nothing', function (): void {
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    Role::findByName('administrator')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::query()->where('name', 'administrator')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'backups.manage')->count())->toBe(1);
});

it('gives backups.manage to administrators and nobody else on a fresh site', function (): void {
    $this->seed(RoleSeeder::class);

    expect(RoleSeeder::PERMISSIONS)->toContain('backups.manage')
        ->and(User::factory()->create()->assignRole('administrator')->can('backups.manage'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('editor')->can('backups.manage'))->toBeFalse();
});
