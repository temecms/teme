<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->added = ['ai.use', 'settings.ai.manage', 'ai-activity.view'];

    // A site as it stood before these three existed: RoleSeeder's grants, less them.
    $this->seed(RoleSeeder::class);
    Permission::query()->whereIn('name', $this->added)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2026_11_10_100000_add_ai_permissions.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('brings AI\'s three permissions to the roles RoleSeeder gives them, on a site seeded before they existed (I-312)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    $held = fn (string $role): array => array_values(array_intersect($this->added, Role::findByName($role)->permissions->pluck('name')->all()));

    expect($held('administrator'))->toBe(['ai.use', 'settings.ai.manage', 'ai-activity.view'])
        ->and($held('editor'))->toBe(['ai.use'])
        ->and($held('author'))->toBe(['ai.use'])
        ->and($held('contributor'))->toBe([]);
});

it('only adds: a grant made by hand stays, a missing role isn\'t made, and a second run changes nothing (I-449)', function (): void {
    Role::findByName('contributor')->givePermissionTo('media.upload');
    Role::findByName('editor')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('contributor')->hasPermissionTo('media.upload'))->toBeTrue()
        ->and(Role::findByName('contributor')->hasPermissionTo('ai.use'))->toBeFalse()
        ->and(Role::query()->where('name', 'editor')->exists())->toBeFalse()
        ->and(Permission::query()->whereIn('name', $this->added)->count())->toBe(3);
});
