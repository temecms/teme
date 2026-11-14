<?php

declare(strict_types=1);

use App\Domain\Users\Notifications\PasswordChangedNotice;
use App\Domain\Users\Notifications\ResetPasswordLink;
use App\Domain\Users\Operations\Input\ResetPasswordInput;
use App\Domain\Users\Operations\ResetPassword;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $data
 *
 * @return array{status: int, body: string, seconds: float}
 */
function timedPost(string $uri, array $data): array
{
    $started = hrtime(true);
    $response = test()->post($uri, $data);

    return [
        'status' => $response->getStatusCode(),
        // The whole errors payload, as the session stores it after the request.
        'body' => (string) session('status').json_encode(session('errors')),
        'seconds' => (hrtime(true) - $started) / 1e9,
    ];
}

function floorSeconds(): float
{
    return (int) config('auth.timebox_duration') / 1e6;
}

it('answers a reset request identically for known and unknown addresses', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'known@example.com']);

    $known = timedPost('/admin/forgot-password', ['email' => 'known@example.com']);
    $unknown = timedPost('/admin/forgot-password', ['email' => 'nobody@example.com']);

    expect($unknown['status'])->toBe($known['status'])
        ->and($unknown['body'])->toBe($known['body'])
        ->and($known['body'])->toContain('If an account exists for that address');
});

it('answers a throttled reset request the same way', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'known@example.com']);

    $first = timedPost('/admin/forgot-password', ['email' => 'known@example.com']);
    $throttled = timedPost('/admin/forgot-password', ['email' => 'known@example.com']);

    expect($throttled['status'])->toBe($first['status'])
        ->and($throttled['body'])->toBe($first['body']);
});

it('answers sign-in failures identically for known and unknown addresses', function (): void {
    User::factory()->create(['email' => 'known@example.com']);

    $known = timedPost('/admin/login', ['email' => 'known@example.com', 'password' => 'wrong-horse-battery-staple']);
    $unknown = timedPost('/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong-horse-battery-staple']);

    expect($unknown['status'])->toBe($known['status'])
        ->and($unknown['body'])->toBe($known['body']);
});

it('holds every credential endpoint to the response-time floor, whatever the outcome', function (string $uri, array $data): void {
    User::factory()->create(['email' => 'known@example.com']);
    User::factory()->withTwoFactor()->create(['email' => 'second-factor@example.com']);
    Notification::fake();

    expect(timedPost($uri, $data)['seconds'])->toBeGreaterThanOrEqual(floorSeconds());
})->with([
    'sign-in, unknown address' => ['/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong-horse-battery-staple']],
    'sign-in, wrong password' => ['/admin/login', ['email' => 'known@example.com', 'password' => 'wrong-horse-battery-staple']],
    'sign-in, two-factor account, wrong password' => ['/admin/login', ['email' => 'second-factor@example.com', 'password' => 'wrong-horse-battery-staple']],
    'reset request, unknown address' => ['/admin/forgot-password', ['email' => 'nobody@example.com']],
    'reset, unknown address' => ['/admin/reset-password', ['token' => 'x', 'email' => 'nobody@example.com', 'password' => 'correct-horse-battery-staple', 'password_confirmation' => 'correct-horse-battery-staple']],
]);

it('queues the reset mail', function (): void {
    expect(new ResetPasswordLink('token'))->toBeInstanceOf(ShouldQueue::class)
        ->and(new PasswordChangedNotice)->toBeInstanceOf(ShouldQueue::class);
});

it('builds the reset link from the site address, never from the request Host', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'known@example.com']);

    $this->withHeader('Host', 'attacker.example')->post('/admin/forgot-password', ['email' => 'known@example.com']);

    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, rtrim((string) config('app.url'), '/').'/admin/reset-password/')
            && ! str_contains($url, 'attacker.example');
    });
});

it('sends a notice to the account after a reset', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    app(ResetPassword::class)->execute(new ResetPasswordInput(
        userId: (string) $user->id,
        token: $token,
        password: 'a-brand-new-long-passphrase',
        passwordConfirmation: 'a-brand-new-long-passphrase',
    ));

    Notification::assertSentTo($user, PasswordChangedNotice::class);
});
