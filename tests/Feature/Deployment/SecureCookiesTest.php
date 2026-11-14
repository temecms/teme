<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
use App\Http\Admin\Auth\DeviceCookie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // As config/session.php sets them in production over https, before
    // anything that reads them is built.
    config(['app.url' => 'https://localhost', 'session.secure' => true, 'session.cookie' => '__Host-teme-session']);
    app()->forgetInstance('cookie');
    Cookie::clearResolvedInstance('cookie');
    app()->forgetInstance('session');
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
});

it('makes every cookie a sign-in sets Secure, and the session’s a __Host- cookie (I-380)', function (): void {
    $user = User::factory()->create();

    $response = $this->post('https://localhost/admin/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
    $cookies = collect($response->headers->getCookies())->keyBy(fn (ResponseCookie $cookie): string => $cookie->getName());
    $session = $cookies->get('__Host-teme-session');

    expect($cookies->keys()->all())->toContain('__Host-teme-session', 'XSRF-TOKEN', SignedInMarker::COOKIE, DeviceCookie::NAME)
        ->and($cookies->keys()->first(fn (string $name): bool => str_starts_with($name, 'remember_web_')))->not->toBeNull()
        ->and($cookies->reject(fn (ResponseCookie $cookie): bool => $cookie->isSecure())->keys()->all())->toBe([])
        ->and($session?->getPath())->toBe('/')
        ->and($session?->getDomain())->toBeNull()
        ->and(SignedInMarker::COOKIE)->toBe('teme_auth');
});

it('clears the signed-in marker at sign-out with a Secure cookie too', function (): void {
    $response = $this->actingAs(User::factory()->create())->post('https://localhost'.route('logout', absolute: false));
    $marker = $response->getCookie(SignedInMarker::COOKIE, decrypt: false);

    expect($marker?->isCleared())->toBeTrue()
        ->and($marker?->isSecure())->toBeTrue();
});
