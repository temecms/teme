<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->added = ['settings.seo.manage', 'redirects.manage', 'page-cache.clear', 'site-code.manage'];

    // A site as it stood before these four existed: RoleSeeder's grants, less them.
    $this->seed(RoleSeeder::class);
    Permission::query()->whereIn('name', $this->added)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2026_10_25_100000_add_seo_permissions.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('brings Search and social\'s, redirects\', the page cache\'s and site code\'s permissions to the roles RoleSeeder gives them, on a site seeded before they existed (I-850)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    $held = fn (string $role): array => array_values(array_intersect($this->added, Role::findByName($role)->permissions->pluck('name')->all()));

    expect($held('administrator'))->toBe($this->added)
        ->and($held('editor'))->toBe(['redirects.manage'])
        ->and($held('author'))->toBe([])
        ->and($held('contributor'))->toBe([]);
});

it('only adds: a grant made by hand stays, a missing role isn\'t made, and a second run changes nothing (I-449)', function (): void {
    Role::findByName('author')->givePermissionTo('settings.general.manage');
    Role::findByName('editor')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('author')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::query()->where('name', 'editor')->exists())->toBeFalse()
        ->and(Permission::query()->whereIn('name', $this->added)->count())->toBe(4);
});
