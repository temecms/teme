<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('says Teme’s own words for a wrong password, too many tries, a dead reset link and a finished reset', function (): void {
    expect(__('auth.failed'))->toBe('That email address and password don’t match an account.')
        ->and(__('auth.throttle', ['seconds' => 30]))->toBe('Too many sign-in attempts. Try again in 30 seconds.')
        ->and(__('passwords.token'))->toBe('This reset link no longer works. Ask for a new one.')
        ->and(__('passwords.reset'))->toBe('Password changed. Sign in with your new password.');
});

it('keeps Laravel’s own line for every key Teme leaves alone', function (): void {
    expect(__('auth.password'))->toBe('The provided password is incorrect.')
        ->and(__('passwords.sent'))->toBe('We have emailed your password reset link.')
        ->and(__('passwords.throttled'))->toBe('Please wait before retrying.')
        ->and(__('passwords.user'))->toBe("We can't find a user with that email address.")
        ->and(__('validation.required', ['attribute' => 'name']))->toBe('The name field is required.');
});

it('says Teme’s own words for a two-factor code that didn’t work', function (): void {
    expect(__('The provided two factor authentication code was invalid.'))->toBe('That code didn’t work. Type the newest code your app shows.')
        ->and(__('The provided two factor recovery code was invalid.'))->toBe('That recovery code didn’t work. Each code works only once.');
});

it('refuses a wrong password in those words', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'That email address and password don’t match an account.']);
});

it('refuses a reset link that no longer works in those words', function (): void {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors(['email' => 'This reset link no longer works. Ask for a new one.']);
});
