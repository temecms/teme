<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Fixtures\Deployment\Edge;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Edge::production('https://example.com');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
});

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('sends a year of HSTS on an https answer in production (I-380)', function (): void {
    $this->get('https://example.com/up')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends it on an answer that came through Traefik over https', function (): void {
    Edge::trustProxies('private');

    $this->withServerVariables(Edge::viaTraefik(Edge::READER))
        ->get('http://example.com/up')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends it on an error too', function (): void {
    $this->post('https://example.com/up')->assertStatus(405)->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends nothing over plain http', function (): void {
    $this->get('http://example.com/up')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

it('sends nothing outside production', function (): void {
    app()->instance('env', 'staging');

    $this->get('https://example.com/up')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

it('sends nothing when APP_URL is http', function (): void {
    Edge::production('http://example.com');

    $this->get('https://example.com/up')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

it('sends what TEME_HSTS says, and nothing when it is empty', function (string $configured, ?string $sent): void {
    config(['teme.deployment.hsts' => $configured]);

    $response = $this->get('https://example.com/up')->assertOk();

    $sent === null
        ? $response->assertHeaderMissing('Strict-Transport-Security')
        : $response->assertHeader('Strict-Transport-Security', $sent);
})->with([
    'preloaded, with subdomains' => ['max-age=63072000; includeSubDomains; preload', 'max-age=63072000; includeSubDomains; preload'],
    'empty' => ['', null],
]);
