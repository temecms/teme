<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('shows the profile page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/account/profile')
        ->assertOk();
});

it('updates the profile through the UpdateProfile Operation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->patch('/admin/account/profile', ['name' => 'New Name', 'email' => 'NEW@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/account/profile');

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

it('returns validation errors and changes nothing for a bad email', function (): void {
    $user = User::factory()->create(['name' => 'Kept']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->patch('/admin/account/profile', ['name' => 'Changed', 'email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    expect($user->refresh()->name)->toBe('Kept');
});

it('offers no way to delete one\'s own account (I-102)', function (): void {
    $this->actingAs(User::factory()->create())
        ->delete('/admin/account/profile')
        ->assertMethodNotAllowed();
});

it('changes the password through the ChangePassword Operation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/admin/account/password', [
            'current_password' => 'password',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('correct-horse-battery-staple', $user->refresh()->password))->toBeTrue();
});

it('refuses a password change with the wrong current password', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/admin/account/password', [
            'current_password' => 'wrong',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])
        ->assertSessionHasErrors('current_password');
});

it('asks for the password again before showing the security page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/account/security')
        ->assertRedirect('/admin/user/confirm-password');
});

it('shares only the documented user fields with the browser', function (): void {
    $user = User::factory()->create();

    $request = Request::create('/admin');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $user);

    $shared = app(HandleInertiaRequests::class)->share($request);

    expect(array_keys($shared['auth']['user']))->toBe(['id', 'name', 'email', 'emailVerified', 'capabilities', 'role', 'picture']);
});
