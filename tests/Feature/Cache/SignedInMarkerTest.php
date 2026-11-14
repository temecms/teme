<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;

uses(RefreshDatabase::class);

it('marks the browser at sign-in, with a session cookie that holds nothing, which core\'s script may read (D6, I-465)', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $response->assertCookie(SignedInMarker::COOKIE, '1');
    $cookie = $response->getCookie(SignedInMarker::COOKIE, decrypt: false);

    expect($cookie?->getExpiresTime())->toBe(0)
        ->and($cookie?->isHttpOnly())->toBeFalse()
        ->and($cookie?->getSameSite())->toBe('lax');
});

it('clears the marker at sign-out', function (): void {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertCookieExpired(SignedInMarker::COOKIE);
});

it('marks a browser a remember-me cookie signs in again', function (): void {
    event(new Login('web', User::factory()->create(), true));

    expect(Cookie::queued(SignedInMarker::COOKIE)?->getValue())->toBe('1');
});
