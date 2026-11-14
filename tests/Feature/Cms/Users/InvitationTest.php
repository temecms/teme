<?php

declare(strict_types=1);

use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Users\InvitationUrl;
use App\Domain\Users\Notifications\InvitationLink;
use App\Domain\Users\Operations\AcceptInvitation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\ResendInvitationInput;
use App\Domain\Users\Operations\Input\ResetPasswordInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\ResendInvitation;
use App\Domain\Users\Operations\ResetPassword;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Notification::fake();
});

/**
 * @return array{userId: int, token: string}
 */
function usersInvite(?User $actor, string $email = 'new@example.com', string $role = 'author', bool $deliver = true): array
{
    return app(InviteUser::class)->execute(new InviteUserInput(
        name: 'New Person',
        email: $email,
        role: $role,
        actorId: $actor === null ? null : (string) $actor->id,
        deliver: $deliver,
    ));
}

it('invites a person with a role, an unusable password and a queued link', function (): void {
    $this->withElevation();

    $result = usersInvite($this->admin, ' New@Example.com ');
    $user = User::query()->findOrFail($result['userId']);

    expect($user->email)->toBe('new@example.com')
        ->and($user->hasRole('author'))->toBeTrue()
        ->and($user->invited_at)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('', $user->password))->toBeFalse()
        ->and(Password::broker('invitations')->tokenExists($user, $result['token']))->toBeTrue()
        ->and(new InvitationLink('t', 1))->toBeInstanceOf(ShouldQueue::class);

    Notification::assertSentTo($user, InvitationLink::class, fn (InvitationLink $link): bool => str_starts_with(
        $link->toMail($user)->actionUrl,
        rtrim((string) config('app.url'), '/').'/admin/invitation/',
    ));
});

it('keeps an invitation link valid for seven days', function (): void {
    $this->withElevation();
    $result = usersInvite($this->admin);
    $user = User::query()->findOrFail($result['userId']);

    $this->travel(6)->days();
    expect(Password::broker('invitations')->tokenExists($user, $result['token']))->toBeTrue();

    $this->travel(2)->days();
    expect(Password::broker('invitations')->tokenExists($user, $result['token']))->toBeFalse();
});

it('refuses an address already in use, and an unknown role', function (string $email, string $role): void {
    $this->withElevation();
    User::factory()->create(['email' => 'taken@example.com']);

    expect(fn () => usersInvite($this->admin, $email, $role))->toThrow(ValidationException::class);
})->with([
    'taken address' => ['taken@example.com', 'author'],
    'unknown role' => ['fresh@example.com', 'overlord'],
]);

it('writes nothing for someone who may not manage users', function (): void {
    $this->withElevation();
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => usersInvite($editor))->toThrow(AuthorizationException::class);
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('writes nothing without a fresh confirmation', function (): void {
    expect(fn () => usersInvite($this->admin))->toThrow(ElevationRequiredException::class);
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('refuses a null actor outside the operator\'s shell', function (): void {
    expect(fn () => usersInvite(null))->toThrow(AuthorizationException::class);
    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('lets the invited person set a password, which verifies their address and ends the invitation', function (): void {
    $this->withElevation();
    $result = usersInvite($this->admin);

    app(AcceptInvitation::class)->execute(new AcceptInvitationInput('new@example.com', $result['token'], 'a-brand-new-long-passphrase', 'a-brand-new-long-passphrase'));

    $user = User::query()->findOrFail($result['userId']);

    expect(Hash::check('a-brand-new-long-passphrase', $user->password))->toBeTrue()
        ->and($user->invited_at)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Password::broker('invitations')->tokenExists($user, $result['token']))->toBeFalse();
});

it('writes nothing for a wrong invitation token', function (): void {
    $this->withElevation();
    $result = usersInvite($this->admin);

    expect(fn () => app(AcceptInvitation::class)->execute(new AcceptInvitationInput('new@example.com', 'wrong', 'a-brand-new-long-passphrase', 'a-brand-new-long-passphrase')))
        ->toThrow(AuthorizationException::class);

    expect(User::query()->findOrFail($result['userId'])->invited_at)->not->toBeNull();
});

it('re-sends a pending invitation with a fresh link, and only a pending one', function (): void {
    $this->withElevation();
    $result = usersInvite($this->admin);

    app(ResendInvitation::class)->execute(new ResendInvitationInput((string) $result['userId'], (string) $this->admin->id));

    Notification::assertSentToTimes(User::query()->findOrFail($result['userId']), InvitationLink::class, 2);

    $active = User::factory()->create();

    expect(fn () => app(ResendInvitation::class)->execute(new ResendInvitationInput((string) $active->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class);
});

it('treats a completed password reset as accepting the invitation', function (): void {
    $invited = User::factory()->invited()->create();

    app(ResetPassword::class)->execute(new ResetPasswordInput(
        userId: (string) $invited->id,
        token: Password::broker()->createToken($invited),
        password: 'a-brand-new-long-passphrase',
        passwordConfirmation: 'a-brand-new-long-passphrase',
    ));

    $invited->refresh();

    expect($invited->invited_at)->toBeNull()
        ->and($invited->email_verified_at)->not->toBeNull();
});

it('accepts through the invitation page and sends the person to sign in', function (): void {
    $this->withElevation();
    $result = usersInvite($this->admin);

    $this->get(InvitationUrl::for($result['token'], 'new@example.com'))->assertOk();

    $this->post('/admin/invitation', [
        'token' => $result['token'],
        'email' => 'new@example.com',
        'password' => 'a-brand-new-long-passphrase',
        'password_confirmation' => 'a-brand-new-long-passphrase',
    ])->assertRedirect(route('login'));

    $this->post('/admin/invitation', [
        'token' => $result['token'],
        'email' => 'new@example.com',
        'password' => 'another-long-passphrase-here',
        'password_confirmation' => 'another-long-passphrase-here',
    ])->assertSessionHasErrors('email');
});
