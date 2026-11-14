<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->added = ['imports.run', 'jobs.manage'];

    // A site as it stood before the importer: RoleSeeder's grants, less these two.
    $this->seed(RoleSeeder::class);
    Permission::query()->whereIn('name', $this->added)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2026_11_21_100400_add_import_and_jobs_permissions.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('gives administrators alone the rights to import a site and to manage background jobs, on a site seeded before they existed (I-1707)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    $held = fn (string $role): array => array_values(array_intersect($this->added, Role::findByName($role)->permissions->pluck('name')->all()));

    expect($held('administrator'))->toEqualCanonicalizing($this->added)
        ->and($held('editor'))->toBe([])
        ->and($held('author'))->toBe([])
        ->and($held('contributor'))->toBe([]);
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
