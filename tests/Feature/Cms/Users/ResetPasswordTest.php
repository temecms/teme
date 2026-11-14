<?php

declare(strict_types=1);

use App\Domain\Users\Operations\Input\ResetPasswordInput;
use App\Domain\Users\Operations\ResetPassword;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;

uses(RefreshDatabase::class);

it('resets the password for the holder of a valid reset token', function (): void {
    $user = User::factory()->create();
    $token = PasswordBroker::broker()->createToken($user);

    app(ResetPassword::class)->execute(new ResetPasswordInput(
        userId: (string) $user->id,
        token: $token,
        password: 'correct-horse-battery-staple',
        passwordConfirmation: 'correct-horse-battery-staple',
    ));

    expect(Hash::check('correct-horse-battery-staple', $user->refresh()->password))->toBeTrue();
});

it('writes nothing without a valid reset token', function (): void {
    $user = User::factory()->create();

    expect(fn () => app(ResetPassword::class)->execute(new ResetPasswordInput(
        userId: (string) $user->id,
        token: 'forged-token',
        password: 'correct-horse-battery-staple',
        passwordConfirmation: 'correct-horse-battery-staple',
    )))->toThrow(AuthorizationException::class);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('rotates the remember token on reset', function (): void {
    $user = User::factory()->create();
    $before = $user->remember_token;
    $token = PasswordBroker::broker()->createToken($user);

    app(ResetPassword::class)->execute(new ResetPasswordInput(
        userId: (string) $user->id,
        token: $token,
        password: 'correct-horse-battery-staple',
        passwordConfirmation: 'correct-horse-battery-staple',
    ));

    expect($user->refresh()->remember_token)->not->toBe($before);
});
