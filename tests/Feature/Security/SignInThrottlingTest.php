<?php

declare(strict_types=1);

use App\Http\Admin\Auth\DeviceCookie;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'editor@example.com', 'password' => 'correct-horse-battery-staple']);
});

/**
 * @param  array<string, string>  $cookies
 */
function signIn(string $password, string $ip, array $cookies = []): TestResponse
{
    $request = test()->withServerVariables(['REMOTE_ADDR' => $ip]);

    foreach ($cookies as $name => $value) {
        $request = $request->withCookie($name, $value);
    }

    $response = $request->post('/admin/login', ['email' => 'editor@example.com', 'password' => $password]);

    Auth::forgetGuards();

    return $response;
}

it('stops one address after five failures on one account', function (): void {
    foreach (range(1, 5) as $attempt) {
        signIn('wrong-horse-battery-staple', '198.51.100.7');
    }

    signIn('correct-horse-battery-staple', '198.51.100.7')->assertSessionHasErrors('email');
    $this->assertGuest();

    signIn('correct-horse-battery-staple', '198.51.100.8')->assertSessionHasNoErrors();
});

it('stops an untrusted browser after ten failures on one account from anywhere', function (): void {
    foreach (range(1, 10) as $attempt) {
        signIn('wrong-horse-battery-staple', "203.0.113.{$attempt}");
    }

    signIn('correct-horse-battery-staple', '203.0.113.200')->assertSessionHasErrors('email');
});

it('lets the account holder in from a browser they have used before, however many strangers failed', function (): void {
    foreach (range(1, 10) as $attempt) {
        signIn('wrong-horse-battery-staple', "203.0.113.{$attempt}");
    }

    signIn('correct-horse-battery-staple', '192.0.2.44', [DeviceCookie::NAME => DeviceCookie::valueFor('editor@example.com')])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin');
});

it('issues a device cookie on a successful sign-in', function (): void {
    signIn('correct-horse-battery-staple', '192.0.2.44')->assertCookie(DeviceCookie::NAME);
});

it('throttles made-up addresses exactly as it throttles real ones', function (): void {
    foreach (range(1, 5) as $attempt) {
        test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong-horse-battery-staple']);
    }

    $throttled = test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->post('/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong-horse-battery-staple']);

    $throttled->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many');
});

it('stops one address trying many accounts', function (): void {
    foreach (range(1, 50) as $attempt) {
        test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post('/admin/login', ['email' => "guess{$attempt}@example.com", 'password' => 'wrong-horse-battery-staple']);
    }

    signIn('correct-horse-battery-staple', '198.51.100.10')->assertSessionHasErrors('email');
});

it('clears the per-account counter when the password is reset', function (): void {
    foreach (range(1, 10) as $attempt) {
        signIn('wrong-horse-battery-staple', "203.0.113.{$attempt}");
    }

    event(new PasswordReset($this->user));

    signIn('correct-horse-battery-staple', '203.0.113.201')->assertSessionHasNoErrors();
});

it('ignores X-Forwarded-For unless the proxy is trusted', function (): void {
    foreach (range(1, 5) as $attempt) {
        test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->withHeader('X-Forwarded-For', "10.0.0.{$attempt}")
            ->post('/admin/login', ['email' => 'editor@example.com', 'password' => 'wrong-horse-battery-staple']);
    }

    signIn('correct-horse-battery-staple', '198.51.100.11')->assertSessionHasErrors('email');
});

it('caps remember-me at the configured number of days', function (): void {
    $response = test()->post('/admin/login', ['email' => 'editor@example.com', 'password' => 'correct-horse-battery-staple', 'remember' => '1']);

    $recaller = collect($response->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));

    expect($recaller)->not->toBeNull()
        ->and($recaller->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(14)->addMinute()->getTimestamp())
        ->and($recaller->getExpiresTime())->toBeGreaterThan(now()->addDays(13)->getTimestamp());
});
