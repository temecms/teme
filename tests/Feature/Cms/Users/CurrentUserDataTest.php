<?php

declare(strict_types=1);

use App\Domain\Users\Queries\CurrentUserData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns exactly the documented fields — never the model', function (): void {
    $user = User::factory()->create();

    $data = app(CurrentUserData::class)->find($user->id);

    expect($data)->toBeArray()
        ->and(array_keys($data))->toBe(['id', 'name', 'email', 'emailVerified', 'capabilities']);
});

it('returns null for no user and for an unknown user', function (): void {
    expect(app(CurrentUserData::class)->find(null))->toBeNull()
        ->and(app(CurrentUserData::class)->find(999_999))->toBeNull();
});

it('derives capabilities from the user\'s role', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');

    $capabilities = app(CurrentUserData::class)->find($author->id)['capabilities'];

    expect($capabilities)->toContain('content.create')
        ->and($capabilities)->not->toContain('content.edit.any');
});
