<?php

declare(strict_types=1);

use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Changing an email address needs elevation; these tests are not about it.
beforeEach(fn () => $this->withElevation());

function updateProfile(User $user, string $name, string $email, ?string $actorId = null): void
{
    app(UpdateProfile::class)->execute(new UpdateProfileInput(
        userId: (string) $user->id,
        name: $name,
        email: $email,
        actorId: $actorId ?? (string) $user->id,
    ));
}

it('updates the name and email and clears verification when the email changes', function (): void {
    $user = User::factory()->create();

    updateProfile($user, 'New Name', 'new@example.com');

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

it('keeps verification when the email is unchanged', function (): void {
    $user = User::factory()->create();

    updateProfile($user, 'New Name', $user->email);

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

it('trims the name and lowercases the email', function (): void {
    $user = User::factory()->create();

    updateProfile($user, '  Spaced Name  ', '  Mixed.Case@Example.COM ');

    $user->refresh();
    expect($user->name)->toBe('Spaced Name')
        ->and($user->email)->toBe('mixed.case@example.com');
});

it('refuses an email that another account uses', function (): void {
    $taken = User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    expect(fn () => updateProfile($user, 'Name', 'taken@example.com'))
        ->toThrow(ValidationException::class);

    expect($user->refresh()->email)->not->toBe($taken->email);
});

it('writes nothing when someone else tries to update the profile', function (): void {
    $user = User::factory()->create(['name' => 'Original']);
    $stranger = User::factory()->create();

    expect(fn () => updateProfile($user, 'Hijacked', 'hijack@example.com', (string) $stranger->id))
        ->toThrow(AuthorizationException::class);

    expect($user->refresh()->name)->toBe('Original');
});

it('saves a trimmed bio', function (): void {
    $user = User::factory()->create();

    app(UpdateProfile::class)->execute(new UpdateProfileInput(
        userId: (string) $user->id, name: $user->name, email: $user->email, actorId: (string) $user->id,
        bio: '  Writes about herons.  ',
    ));

    expect($user->refresh()->bio)->toBe('Writes about herons.');
});

it('refuses a bio over 1,000 characters', function (): void {
    $user = User::factory()->create();

    expect(fn () => app(UpdateProfile::class)->execute(new UpdateProfileInput(
        userId: (string) $user->id, name: $user->name, email: $user->email, actorId: (string) $user->id,
        bio: str_repeat('a', 1001),
    )))->toThrow(ValidationException::class);

    expect($user->refresh()->bio)->toBeNull();
});

it('writes no bio when someone else tries to set it', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    expect(fn () => app(UpdateProfile::class)->execute(new UpdateProfileInput(
        userId: (string) $user->id, name: $user->name, email: $user->email, actorId: (string) $stranger->id,
        bio: 'Hijacked',
    )))->toThrow(AuthorizationException::class);

    expect($user->refresh()->bio)->toBeNull();
});
