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
    // A site as it stood before plugins.manage existed: RoleSeeder's grants, less it.
    $this->seed(RoleSeeder::class);
    Permission::query()->where('name', 'plugins.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2027_03_10_100100_add_plugins_permission.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('gives administrators plugins.manage on an existing site, adding only', function (): void {
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    Role::findByName('contributor')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('administrator')->hasPermissionTo('plugins.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('plugins.manage'))->toBeFalse()
        ->and(Role::findByName('author')->hasPermissionTo('plugins.manage'))->toBeFalse()
        ->and(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::query()->where('name', 'contributor')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'plugins.manage')->count())->toBe(1);
});

it('lists plugins.manage among the roles’ permissions', function (): void {
    $this->seed(RoleSeeder::class);

    expect(RoleSeeder::PERMISSIONS)->toContain('plugins.manage')
        ->and(User::factory()->create()->assignRole('administrator')->can('plugins.manage'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('editor')->can('plugins.manage'))->toBeFalse();
});
