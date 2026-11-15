<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('inbound');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance('inbound.hits', ['callback' => 0]);
    app(PublicRouteRegistry::class)->flush();

    $settings = PluginFixtures::manifest('inbound')['contributes']['settings'];
    app(SettingsRegistry::class)->group(
        DeclaredSettings::group('inbound', 'Inbound', 'Inbound secrets.', '1.0.0', $settings),
        'tests/inbound',
    );
    app(SettingsWriter::class)->write(new SettingsWrite('plugin.inbound', [], secrets: ['connection_token' => 'shared-secret']));

    app(EnablePlugin::class)->execute(new EnablePluginInput(
        name: 'tests/inbound',
        version: '1.0.0',
        capabilities: ['routes.public'],
        hosts: [],
        hooks: [],
        actorId: null,
    ));
    app(PublicRouteRegistry::class)->flush();

    $this->hits = fn (): int => (int) (app('inbound.hits')['callback'] ?? 0);
});

afterEach(fn () => PluginFixtures::forget());

it('accepts a secret from the header and refuses a wrong or missing one', function (): void {
    $this->post('/inbound/callback', [], ['X-Plugin-Token' => 'shared-secret'])
        ->assertOk()
        ->assertSee('ok', false);
    expect(($this->hits)())->toBe(1);

    $this->post('/inbound/callback', [], ['X-Plugin-Token' => 'wrong'])
        ->assertUnauthorized()
        ->assertSee('', false);
    expect(($this->hits)())->toBe(1);

    $this->post('/inbound/callback')->assertUnauthorized();
    expect(($this->hits)())->toBe(1);
});

it('accepts a secret from a form field and from the query', function (): void {
    $this->call('POST', '/inbound/callback-field', content: 'token=shared-secret', server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ])->assertOk();
    expect(($this->hits)())->toBe(1);

    $this->post('/inbound/callback-query?token=shared-secret')
        ->assertOk();
    expect(($this->hits)())->toBe(2);
});

it('accepts a correct hmac and refuses a wrong one', function (): void {
    $body = '{"a":1}';
    $signature = hash_hmac('sha256', $body, 'shared-secret');

    $this->call('POST', '/inbound/hmac', content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature,
    ])->assertOk();
    expect(($this->hits)())->toBe(1);

    $this->call('POST', '/inbound/hmac', content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => 'nope',
    ])->assertUnauthorized();
    expect(($this->hits)())->toBe(1);
});

it('calls the plugin’s custom verifier class', function (): void {
    $this->post('/inbound/custom', [], ['X-Custom' => 'shared-secret'])->assertOk();
    expect(($this->hits)())->toBe(1);

    $this->post('/inbound/custom', [], ['X-Custom' => 'wrong'])->assertUnauthorized();
    expect(($this->hits)())->toBe(1);
});

it('answers 413 over maxBody before verification, so the handler never runs', function (): void {
    $this->call('POST', '/inbound/large', content: str_repeat('x', 65), server: [
        'CONTENT_TYPE' => 'text/plain',
        'HTTP_X_PLUGIN_TOKEN' => 'shared-secret',
    ])->assertStatus(413);

    expect(($this->hits)())->toBe(0);
});

it('answers 413 from a Content-Length over maxBody before reading the body', function (): void {
    $this->call('POST', '/inbound/large', content: 'x', server: [
        'CONTENT_TYPE' => 'text/plain',
        'CONTENT_LENGTH' => '65',
        'HTTP_X_PLUGIN_TOKEN' => 'shared-secret',
    ])->assertStatus(413);

    expect(($this->hits)())->toBe(0);
});

it('reads a form field from a real form body and refuses a wrong one', function (): void {
    $this->call('POST', '/inbound/callback-field', content: 'token=wrong', server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ])->assertUnauthorized();

    expect(($this->hits)())->toBe(0);
});
