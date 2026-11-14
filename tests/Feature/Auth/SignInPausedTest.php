<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** A wrong password for $email from $ip, the guard forgotten so the next request starts clean. */
function spFail(string $email, string $ip): TestResponse
{
    $response = test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/admin/login', ['email' => $email, 'password' => 'wrong-horse-battery-staple']);

    Auth::forgetGuards();

    return $response;
}

/** Five failures for $email from $ip, then the sixth try, which is refused. */
function spRefused(string $email, string $ip): TestResponse
{
    foreach (range(1, 5) as $attempt) {
        spFail($email, $ip);
    }

    return spFail($email, $ip);
}

/** The sign-in page's `retryAfter`, as the page receives it. */
function spRetryAfter(): ?int
{
    $retryAfter = null;

    test()->get('/admin/login')->assertOk()->assertInertia(function (Assert $page) use (&$retryAfter): void {
        $page->has('retryAfter');
        $retryAfter = $page->toArray()['props']['retryAfter'];
    });

    return $retryAfter;
}

it('gives the sign-in page no wait when nothing was refused', function (): void {
    $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('retryAfter', null));

    spFail('editor@example.com', '198.51.100.30');

    $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('retryAfter', null));
});

it('gives the sign-in page the wait the refusal names, after a throttled try', function (): void {
    $this->freezeTime();

    spRefused('editor@example.com', '198.51.100.20')->assertSessionHasErrors('email');
    $message = session('errors')->first('email');

    expect($message)->toContain('Too many')
        ->and(preg_match('/(\d+) seconds/', $message, $match))->toBe(1)
        ->and(spRetryAfter())->toBe((int) $match[1])->toBe(900);
});

it('gives the same wait for a real address and an invented one', function (): void {
    $this->freezeTime();
    User::factory()->create(['email' => 'known@example.com']);

    $known = spRefused('known@example.com', '198.51.100.21');
    $knownErrors = json_encode(session('errors'));
    $knownWait = spRetryAfter();

    $invented = spRefused('nobody@example.com', '198.51.100.22');
    $inventedErrors = json_encode(session('errors'));
    $inventedWait = spRetryAfter();

    expect($knownWait)->not->toBeNull()
        ->and($knownWait)->toBe($inventedWait)
        ->and($known->getStatusCode())->toBe($invented->getStatusCode())
        ->and($knownErrors)->toBe($inventedErrors);
});

it('counts down from the moment of the refusal', function (): void {
    $this->freezeTime();

    spRefused('editor@example.com', '198.51.100.23');
    $this->travel(100)->seconds();

    expect(spRetryAfter())->toBe(800);

    // Refused again: the flash names the 800 seconds still left, and once they pass there is no wait.
    spFail('editor@example.com', '198.51.100.23');
    $this->travel(800)->seconds();

    expect(spRetryAfter())->toBeNull();
});

it('forgets the wait after the page has shown it once', function (): void {
    spRefused('editor@example.com', '198.51.100.24');

    expect(spRetryAfter())->not->toBeNull()
        ->and(spRetryAfter())->toBeNull();
});
