<?php

declare(strict_types=1);

use App\Http\Middleware\StrictTransportSecurity;
use App\Http\Middleware\TrustSiteHost;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Inertia\Middleware\EnsureDeferredCallbacksRun;
use Tests\Fixtures\Deployment\Edge;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Edge::production('https://example.com');
});

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('answers the site’s own host (I-380)', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('https://example.com/up')->assertOk();
});

it('refuses another host, and a subdomain of the site’s, with a 400', function (string $host): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get("https://{$host}/up")->assertStatus(400);
})->with(['evil.example', 'www.example.com', 'example.com.evil.example']);

it('lets a health check inside the container name localhost', function (string $host): void {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get("http://{$host}/up")->assertOk();
})->with(['localhost:8080', '127.0.0.1:8080', 'localhost']);

it('refuses localhost from any address but the container’s own', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => Edge::TRAEFIK])->get('http://localhost:8080/up')->assertStatus(400);
});

it('refuses a host a trusted proxy forwards for another site', function (): void {
    Edge::trustProxies('private');
    Edge::probe();

    $this->withServerVariables(Edge::viaTraefik(Edge::READER, 'evil.example'))
        ->get('http://example.com/_teme/edge-probe')
        ->assertStatus(400);
});

it('never trusts every proxy for a request that names a Forge host', function (): void {
    Edge::trustProxies('');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('https://teme.on-forge.com/up')->assertStatus(400);
});

it('checks the host in every environment but local', function (string $environment, int $status): void {
    app()->instance('env', $environment);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('https://evil.example/up')->assertStatus($status);
})->with([['staging', 400], ['local', 200]]);

it('checks the host before the proxies are read, and sends HSTS outermost', function (): void {
    $global = app(HttpKernel::class)->getGlobalMiddleware();
    // Inertia's own goes outside it, and only passes the response through, so every answer still carries the header.
    $answering = array_values(array_diff($global, [EnsureDeferredCallbacksRun::class]));

    expect($answering[0] ?? null)->toBe(StrictTransportSecurity::class)
        ->and(array_search(TrustSiteHost::class, $global, true))->toBeInt()->toBeLessThan(array_search(TrustProxies::class, $global, true))
        ->and($global)->not->toContain(TrustHosts::class);
});

it('clears the trusted-host list once a request is answered, so later code in the process meets none', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('https://example.com/up')->assertOk();

    expect(fn () => Request::create('http://Other.Example/harbour/')->getSchemeAndHttpHost())->not->toThrow(Throwable::class)
        ->and(Request::create('http://Other.Example/harbour/')->getSchemeAndHttpHost())->toBe('http://other.example');
});
