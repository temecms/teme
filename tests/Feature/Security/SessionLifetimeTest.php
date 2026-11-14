<?php

declare(strict_types=1);

use App\Domain\Users\Operations\ChangePassword;
use App\Domain\Users\Operations\Input\ChangePasswordInput;
use App\Domain\Users\Operations\Input\ResetPasswordInput;
use App\Domain\Users\Operations\ResetPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'editor@example.com', 'password' => 'correct-horse-battery-staple']);
});

function signInThroughTheForm(): void
{
    test()->post('/admin/login', ['email' => 'editor@example.com', 'password' => 'correct-horse-battery-staple'])
        ->assertRedirect('/admin');
    test()->get('/admin')->assertOk();
    Auth::forgetGuards();
}

it('ends a session idle for longer than the limit', function (): void {
    signInThroughTheForm();

    $this->travel(121)->minutes();

    $this->get('/admin')->assertRedirect();
    Auth::forgetGuards();
    $this->get('/admin')->assertRedirect(route('login'));
});

it('keeps an active session going within the limits', function (): void {
    signInThroughTheForm();

    foreach (range(1, 6) as $hour) {
        $this->travel(60)->minutes();
        $this->get('/admin')->assertOk();
        Auth::forgetGuards();
    }
});

it('ends even an active session 24 hours after sign-in', function (): void {
    signInThroughTheForm();

    foreach (range(1, 25) as $hour) {
        $this->travel(60)->minutes();
        $response = $this->get('/admin');
        Auth::forgetGuards();
    }

    $this->get('/admin')->assertRedirect(route('login'));
});

it('ends every other session when the password is changed elsewhere', function (): void {
    signInThroughTheForm();

    // Another device changes the password.
    app(ChangePassword::class)->execute(new ChangePasswordInput(
        userId: (string) $this->user->id,
        currentPassword: 'correct-horse-battery-staple',
        password: 'a-brand-new-long-passphrase',
        passwordConfirmation: 'a-brand-new-long-passphrase',
        actorId: (string) $this->user->id,
    ));

    $this->get('/admin')->assertRedirect(route('login'));
});

it('keeps the session that changed the password', function (): void {
    signInThroughTheForm();

    $this->put('/admin/account/password', [
        'current_password' => 'correct-horse-battery-staple',
        'password' => 'a-brand-new-long-passphrase',
        'password_confirmation' => 'a-brand-new-long-passphrase',
    ])->assertSessionHasNoErrors();
    Auth::forgetGuards();

    $this->get('/admin')->assertOk();
});

it('ends every session when the password is reset', function (): void {
    signInThroughTheForm();

    app(ResetPassword::class)->execute(new ResetPasswordInput(
        userId: (string) $this->user->id,
        token: Password::broker()->createToken($this->user),
        password: 'a-brand-new-long-passphrase',
        passwordConfirmation: 'a-brand-new-long-passphrase',
    ));

    $this->get('/admin')->assertRedirect(route('login'));
});

it('sends the session cookie HttpOnly and SameSite=Lax', function (): void {
    $cookie = collect($this->get('/admin/login')->headers->getCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax');
});
