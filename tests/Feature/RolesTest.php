<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('creates the four roles', function (): void {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['administrator', 'author', 'contributor', 'editor']);
});

it('lets an author create, edit and publish their own but not edit others', function (): void {
    $user = User::factory()->create()->assignRole('author');

    expect($user->can('content.create'))->toBeTrue()
        ->and($user->can('content.edit.own'))->toBeTrue()
        ->and($user->can('content.publish'))->toBeTrue()
        ->and($user->can('content.edit.any'))->toBeFalse();
});

it('lets a contributor create but not publish or edit others', function (): void {
    $user = User::factory()->create()->assignRole('contributor');

    expect($user->can('content.create'))->toBeTrue()
        ->and($user->can('content.publish'))->toBeFalse()
        ->and($user->can('content.edit.any'))->toBeFalse();
});

it('lets an editor publish and edit any content', function (): void {
    $user = User::factory()->create()->assignRole('editor');

    expect($user->can('content.publish'))->toBeTrue()
        ->and($user->can('content.edit.any'))->toBeTrue()
        ->and($user->can('content.delete.any'))->toBeTrue();
});

it('gives an administrator every permission', function (): void {
    $user = User::factory()->create()->assignRole('administrator');

    foreach (Permission::pluck('name') as $permission) {
        expect($user->can($permission))->toBeTrue();
    }
});
