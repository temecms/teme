<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

uses(RefreshDatabase::class);

it('serves the sign-in page under /admin', function (): void {
    $this->get('/admin/login')->assertOk();
});

it('leaves root-level paths to the public site', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with(['/login', '/register', '/dashboard', '/forgot-password']);

it('offers no registration anywhere', function (): void {
    $this->get('/admin/register')->assertNotFound();
    expect(Route::has('register'))->toBeFalse();
});

it('sends guests from the dashboard to the sign-in page', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets a verified user reach the dashboard', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin')->assertOk();
});

it('enables exactly the approved Fortify features (I-100)', function (): void {
    expect(config('fortify.features'))->toBe([
        'reset-passwords',
        'email-verification',
        'two-factor-authentication',
    ]);
});

it('resets passwords through Teme\'s ResetPassword Operation', function (): void {
    expect(app(ResetsUserPasswords::class))->toBeInstanceOf(ResetUserPassword::class);

    $user = User::factory()->create();
    $token = PasswordBroker::broker()->createToken($user);

    $this->post('/admin/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/admin/login');

    expect(Hash::check('correct-horse-battery-staple', $user->refresh()->password))->toBeTrue();
});

it('stores two-factor state on the user', function (): void {
    expect(Schema::hasColumns('users', ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))
        ->toBeTrue();
});

it('keeps the admin middleware off the public site', function (): void {
    $this->get('/')->assertOk();

    expect(Route::getRoutes()->getByName('home')->gatherMiddleware())->not->toContain('admin');
});
