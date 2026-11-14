<?php

declare(strict_types=1);

use App\Domain\Users\Operations\ChangePassword;
use App\Domain\Users\Operations\Input\ChangePasswordInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const NEW_PASSWORD = 'correct-horse-battery-staple';

function changePassword(User $user, string $current, string $new, ?string $actorId = null): void
{
    app(ChangePassword::class)->execute(new ChangePasswordInput(
        userId: (string) $user->id,
        currentPassword: $current,
        password: $new,
        passwordConfirmation: $new,
        actorId: $actorId ?? (string) $user->id,
    ));
}

it('changes the password when the current password is right', function (): void {
    $user = User::factory()->create();

    changePassword($user, 'password', NEW_PASSWORD);

    expect(Hash::check(NEW_PASSWORD, $user->refresh()->password))->toBeTrue();
});

it('refuses a wrong current password and changes nothing', function (): void {
    $user = User::factory()->create();

    expect(fn () => changePassword($user, 'not-the-password', NEW_PASSWORD))
        ->toThrow(ValidationException::class);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('refuses a password shorter than fifteen characters', function (): void {
    $user = User::factory()->create();

    expect(fn () => changePassword($user, 'password', 'fourteen-chars'))
        ->toThrow(ValidationException::class);
});

it('accepts a long password without composition rules', function (): void {
    $user = User::factory()->create();

    changePassword($user, 'password', 'all lowercase words and spaces');

    expect(Hash::check('all lowercase words and spaces', $user->refresh()->password))->toBeTrue();
});

it('rotates the remember token', function (): void {
    $user = User::factory()->create();
    $before = $user->remember_token;

    changePassword($user, 'password', NEW_PASSWORD);

    expect($user->refresh()->remember_token)->not->toBe($before);
});

it('writes nothing when someone else tries to change the password', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    expect(fn () => changePassword($user, 'password', NEW_PASSWORD, (string) $stranger->id))
        ->toThrow(AuthorizationException::class);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});
