<?php

declare(strict_types=1);

use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\SetPicture;
use App\Domain\Users\Queries\CurrentUserData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns exactly the documented fields — never the model', function (): void {
    $user = User::factory()->create();

    $data = app(CurrentUserData::class)->find($user->id);

    expect($data)->toBeArray()
        ->and(array_keys($data))->toBe(['id', 'name', 'email', 'emailVerified', 'capabilities', 'role', 'picture']);
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

it('shares the role’s label and the picture’s address', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $author = User::factory()->create()->assignRole('author');
    $image = pageImage($author, 400, 400);
    app(SetPicture::class)->execute(new SetPictureInput((string) $author->id, $image->id, (string) $author->id));

    $data = app(CurrentUserData::class)->find($author->id);

    expect($data['role'])->toBe('Author')
        ->and($data['picture'])->toBe(Storage::disk('media')->url($image->variants['thumb']['path']));
});
