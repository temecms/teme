<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Checks\StartConfiguration;
use App\Domain\Deployment\Health\Checks\TrustedProxyList;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Deployment\StartRules;

// No database: these checks read configuration alone.

it('reports each start rule a production site breaks, an error in production and a warning elsewhere', function (): void {
    config(['app.key' => '', 'app.url' => 'https://example.com', 'session.driver' => 'file']);
    $problems = StartRules::problems(app('config'));

    expect($problems)->not->toBe([])
        ->and(app(StartConfiguration::class)->examine())->toEqual(array_map(fn (string $problem): Finding => new Finding(Severity::Warning, $problem), $problems));

    app()->instance('env', 'production');

    expect(app(StartConfiguration::class)->examine())->toEqual(array_map(fn (string $problem): Finding => new Finding(Severity::Error, $problem), $problems));
});

it('says production settings are fine when they are', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.cipher' => 'AES-256-CBC',
        'app.url' => 'https://example.com',
        'session.driver' => 'database',
        'session.secure' => true,
        'session.cookie' => '__Host-teme-session',
        'session.domain' => null,
        'session.path' => '/',
        'teme.deployment.hsts' => 'max-age=31536000',
    ]);

    expect(app(StartConfiguration::class)->examine())->toEqual([new Finding(Severity::Ok, 'APP_KEY, APP_URL, the session driver, cookies and TEME_HSTS are as a production site needs.')]);
});

it('says which proxies are trusted when TRUSTED_PROXIES is unset', function (): void {
    config(['app.trusted_proxies' => null]);

    expect(app(TrustedProxyList::class)->examine())->toEqual([new Finding(Severity::Ok, 'TRUSTED_PROXIES is unset, so no proxy is trusted outside production.')]);

    app()->instance('env', 'production');

    expect(app(TrustedProxyList::class)->examine())->toEqual([new Finding(Severity::Ok, 'TRUSTED_PROXIES is unset, so the private ranges a Docker network uses are trusted.')]);
});

it('counts what a set list trusts', function (): void {
    config(['app.trusted_proxies' => 'private, 203.0.113.0/24']);

    expect(app(TrustedProxyList::class)->examine())->toEqual([new Finding(Severity::Ok, 'TRUSTED_PROXIES is private, 203.0.113.0/24: 4 addresses and ranges are trusted.')]);

    config(['app.trusted_proxies' => '203.0.113.7']);

    expect(app(TrustedProxyList::class)->examine())->toEqual([new Finding(Severity::Ok, 'TRUSTED_PROXIES is 203.0.113.7: 1 address or range is trusted.')]);
});

it('warns when a production site trusts no proxy at all', function (): void {
    config(['app.trusted_proxies' => '']);

    expect(app(TrustedProxyList::class)->examine())->toEqual([new Finding(Severity::Ok, 'TRUSTED_PROXIES is empty, so no proxy is trusted.')]);

    app()->instance('env', 'production');

    expect(app(TrustedProxyList::class)->examine())->toEqual([new Finding(Severity::Warning, 'TRUSTED_PROXIES is empty, so behind a proxy every reader looks like the proxy and every link starts http.', 'Behind Traefik alone, remove TRUSTED_PROXIES; behind Cloudflare and Traefik, write private,cloudflare.')]);
});
