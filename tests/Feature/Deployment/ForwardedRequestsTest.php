<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Deployment\Edge;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Edge::probe();
});

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('finds the reader behind Traefik, with the scheme and host Traefik was asked for (I-380)', function (): void {
    Edge::trustProxies('private');

    $this->withServerVariables(Edge::viaTraefik(Edge::READER))
        ->get('http://example.com/_teme/edge-probe')
        ->assertExactJson(['ip' => Edge::READER, 'secure' => true, 'root' => 'https://example.com']);
});

it('trusts Traefik with no configuration in production', function (): void {
    Edge::production('https://example.com');
    Edge::trustProxies(null);

    $this->withServerVariables(Edge::viaTraefik(Edge::READER))
        ->get('http://example.com/_teme/edge-probe')
        ->assertExactJson(['ip' => Edge::READER, 'secure' => true, 'root' => 'https://example.com']);
});

it('trusts no proxy outside production unless told to', function (): void {
    Edge::trustProxies(null);

    $this->withServerVariables(Edge::viaTraefik(Edge::READER))
        ->get('http://example.com/_teme/edge-probe')
        ->assertExactJson(['ip' => Edge::TRAEFIK, 'secure' => false, 'root' => 'http://example.com']);
});

it('finds the reader behind Cloudflare and Traefik once Cloudflare is trusted too', function (): void {
    Edge::trustProxies('private,cloudflare');

    $this->withServerVariables(Edge::viaTraefik(Edge::READER.', '.Edge::CLOUDFLARE))
        ->get('http://example.com/_teme/edge-probe')
        ->assertJson(['ip' => Edge::READER, 'secure' => true]);
});

it('sees Cloudflare’s address, not the reader’s, when only Traefik is trusted', function (): void {
    Edge::trustProxies('private');

    $this->withServerVariables(Edge::viaTraefik(Edge::READER.', '.Edge::CLOUDFLARE))
        ->get('http://example.com/_teme/edge-probe')
        ->assertJson(['ip' => Edge::CLOUDFLARE]);
});

it('finds the reader who writes X-Forwarded-For themselves through Cloudflare', function (): void {
    Edge::trustProxies('private,cloudflare');

    $this->withServerVariables(Edge::viaTraefik('192.0.2.66, '.Edge::READER.', '.Edge::CLOUDFLARE))
        ->get('http://example.com/_teme/edge-probe')
        ->assertJson(['ip' => Edge::READER]);
});

it('believes no forwarded header from an address it doesn’t trust', function (): void {
    Edge::trustProxies('private,cloudflare');

    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.40',
        'HTTP_X_FORWARDED_FOR' => Edge::READER,
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->get('http://example.com/_teme/edge-probe')
        ->assertExactJson(['ip' => '198.51.100.40', 'secure' => false, 'root' => 'http://example.com']);
});

it('stops one reader behind Cloudflare and Traefik after five failures, and not the next reader (I-270)', function (): void {
    Edge::trustProxies('private,cloudflare');
    User::factory()->create(['email' => 'editor@example.com', 'password' => 'correct-horse-battery-staple']);

    $signIn = function (string $reader, string $password): TestResponse {
        $response = $this->withServerVariables(Edge::viaTraefik($reader.', '.Edge::CLOUDFLARE, 'localhost'))
            ->post('http://localhost/admin/login', ['email' => 'editor@example.com', 'password' => $password]);

        Auth::forgetGuards();

        return $response;
    };

    foreach (range(1, 5) as $attempt) {
        $signIn(Edge::READER, 'wrong-horse-battery-staple');
    }

    $signIn(Edge::READER, 'correct-horse-battery-staple')->assertSessionHasErrors('email');
    $signIn(Edge::OTHER_READER, 'correct-horse-battery-staple')->assertSessionHasNoErrors();
});
