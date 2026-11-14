<?php

declare(strict_types=1);

use Tests\Fixtures\Deployment\Environment;

it('makes the session cookie Secure and __Host- in production over https (I-380)', function (): void {
    $session = Environment::with([
        'APP_ENV' => 'production', 'APP_URL' => 'https://example.com', 'APP_NAME' => 'Teme',
        'SESSION_COOKIE' => null, 'SESSION_SECURE_COOKIE' => null,
    ], fn (): array => require config_path('session.php'));

    expect($session['secure'])->toBeTrue()
        ->and($session['cookie'])->toBe('__Host-teme-session');
});

it('treats an unset APP_ENV as production, as Laravel does', function (): void {
    $session = Environment::with([
        'APP_ENV' => null, 'APP_URL' => 'https://example.com', 'APP_NAME' => 'Teme',
        'SESSION_COOKIE' => null, 'SESSION_SECURE_COOKIE' => null,
    ], fn (): array => require config_path('session.php'));

    expect($session['secure'])->toBeTrue()
        ->and($session['cookie'])->toBe('__Host-teme-session');
});

it('keeps Laravel’s cookie over http and outside production', function (string $environment, string $url): void {
    $session = Environment::with([
        'APP_ENV' => $environment, 'APP_URL' => $url, 'APP_NAME' => 'Teme',
        'SESSION_COOKIE' => null, 'SESSION_SECURE_COOKIE' => null,
    ], fn (): array => require config_path('session.php'));

    expect($session['secure'])->toBeFalse()
        ->and($session['cookie'])->toBe('teme-session');
})->with([
    'production over http' => ['production', 'http://example.com'],
    'local over https' => ['local', 'https://teme.test'],
    'staging over https' => ['staging', 'https://staging.example.com'],
]);

it('lets an operator’s own cookie settings stand, for the start rules to judge', function (): void {
    $session = Environment::with([
        'APP_ENV' => 'production', 'APP_URL' => 'https://example.com', 'APP_NAME' => 'Teme',
        'SESSION_COOKIE' => 'site-session', 'SESSION_SECURE_COOKIE' => 'false',
    ], fn (): array => require config_path('session.php'));

    expect($session['secure'])->toBeFalse()
        ->and($session['cookie'])->toBe('site-session');
});

it('reads TRUSTED_PROXIES as null when unset, so production can tell unset from empty', function (?string $value, ?string $read): void {
    $app = Environment::with(['TRUSTED_PROXIES' => $value], fn (): array => require config_path('app.php'));

    expect($app['trusted_proxies'])->toBe($read);
})->with([
    'unset' => [null, null],
    'empty' => ['', ''],
    'named' => ['private,cloudflare', 'private,cloudflare'],
]);

it('sends a year of HSTS unless TEME_HSTS says otherwise', function (?string $value, string $read): void {
    $teme = Environment::with(['TEME_HSTS' => $value], fn (): array => require config_path('teme.php'));

    expect($teme['deployment']['hsts'])->toBe($read);
})->with([
    'unset' => [null, 'max-age=31536000'],
    'empty' => ['', ''],
    'its own' => ['max-age=600', 'max-age=600'],
]);
