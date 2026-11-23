<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;

uses(RefreshDatabase::class);

it('sends a strict script policy with a fresh nonce on admin pages', function (): void {
    $first = $this->get('/admin/login');
    $second = $this->get('/admin/login');

    $policy = (string) $first->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $policy, $nonce);

    expect($policy)->toContain("script-src 'self' 'nonce-")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->not->toContain("'unsafe-eval'")
        ->and($nonce[1] ?? '')->not->toBe('')
        ->and((string) $second->headers->get('Content-Security-Policy'))->not->toContain($nonce[1]);

    $first->assertSee('nonce="'.$nonce[1].'"', false);
});

it('allows the dev server in the admin policy and nonces the refresh preamble', function (): void {
    $hot = tempnam(sys_get_temp_dir(), 'teme-hot');
    file_put_contents($hot, 'http://localhost:5174');
    $this->withVite();
    Vite::useHotFile($hot);
    app()->detectEnvironment(fn (): string => 'local');

    try {
        $response = $this->get('/admin/login');
    } finally {
        unlink($hot);
    }

    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $policy, $nonce);

    expect($policy)->toContain("script-src 'self' 'nonce-{$nonce[1]}' http://localhost:5174")
        ->and($policy)->toContain("style-src 'self' 'unsafe-inline' http://localhost:5174")
        ->and($policy)->toContain("connect-src 'self' http://localhost:5174 ws://localhost:5174")
        ->and($policy)->not->toContain('[');

    $response->assertSee('<script type="module" nonce="'.$nonce[1].'">', false);
});

it('announces the dev server by name, because a CSP source cannot be an IPv6 literal', function (): void {
    $config = (string) file_get_contents(base_path('vite.config.ts'));

    expect($config)->toMatch("/server:\\s*\\{[^}]*host:\\s*'localhost'/s");
});

it('forbids framing and sniffing on admin pages', function (): void {
    $this->get('/admin/login')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('sends no referrer from the reset page, so the token cannot leak', function (): void {
    $this->get('/admin/reset-password/some-token?email=editor@example.com')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('keeps the admin policy off the public site', function (string $mode): void {
    config(['teme.public_csp' => $mode]);
    $response = $this->get('/');

    foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $header) {
        expect((string) $response->headers->get($header))->not->toContain("'nonce-")
            ->and((string) $response->headers->get($header))->not->toContain("frame-ancestors 'none'");
    }
})->with(['off', 'report-only', 'enforce']);
