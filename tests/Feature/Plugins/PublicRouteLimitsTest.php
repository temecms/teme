<?php

declare(strict_types=1);

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\ReportedFailures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('inbound');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance('inbound.hits', ['ping' => 0, 'actor' => 0, 'callback' => 0]);
    app(PublicRouteRegistry::class)->flush();

    $this->enable = function (): void {
        app(EnablePlugin::class)->execute(new EnablePluginInput(
            name: 'tests/inbound',
            version: '1.0.0',
            capabilities: ['routes.public'],
            hosts: [],
            hooks: [],
            actorId: null,
        ));
        app(PublicRouteRegistry::class)->flush();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('answers 429 on the 121st GET in a minute', function (): void {
    ($this->enable)();

    $key = md5('declared-routes'.'declared:inbound:ping:127.0.0.1');

    for ($i = 0; $i < 120; $i++) {
        RateLimiter::hit($key, 60);
    }

    $this->get('/inbound/ping')->assertStatus(429);
});

it('answers 500 with no plugin detail when a handler throws, and records a fault', function (): void {
    ($this->enable)();

    $handler = new ReportedFailures(app());
    $this->app->instance(ExceptionHandler::class, $handler);

    $response = $this->get('/inbound/throw')->assertStatus(500);

    expect($response->getContent())->not->toContain('inbound fixture blew up')
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0][1]['plugin'] ?? null)->toBe('plugin:inbound')
        ->and($handler->reported[0][1]['subscription'] ?? null)->toBe('inbound:throwing');
});

it('lists METHOD path for each route on the consent screen', function (): void {
    ($this->enable)();

    $row = collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/inbound');

    expect($row)->not->toBeNull()
        ->and(collect($row['asks'])->pluck('words')->all())->toContain('Answer addresses on the public site without anyone signing in')
        ->and(collect($row['asks'])->pluck('words')->all())->toContain('Shows a page at /inbound/ping')
        ->and(collect($row['asks'])->pluck('words')->all())->toContain('Answers a form sent to /inbound/callback')
        ->and(collect($row['asks'])->pluck('words')->all())->toContain('Shows a page at / (query author: ^[0-9]+$; Accept: application/activity+json)');
});

it('asks for consent again when an update adds a route', function (): void {
    ($this->enable)();

    $record = PluginRecord::query()->where('name', 'tests/inbound')->sole();
    $agreed = $record->consent;

    $wider = PluginFixtures::copy('inbound', [
        'version' => '1.1.0',
        'routes' => [
            ...PluginFixtures::manifest('inbound')['routes'],
            [
                'id' => 'extra',
                'method' => 'GET',
                'path' => '/{code}/extra',
                'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Ping',
            ],
        ],
    ]);
    PluginFixtures::use($wider);

    $manifest = new Manifest(json_decode((string) file_get_contents($wider.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR));

    expect(Consent::covers($agreed, $manifest))->toBeFalse()
        ->and(Consent::added($agreed, $manifest)['routes'])->toContain('GET /inbound/extra');
});

it('answers 429 on /?author=0 with the activity Accept once its entry’s limit is spent', function (): void {
    ($this->enable)();

    $key = md5('declared-routes'.'declared:inbound:actor:127.0.0.1');

    for ($i = 0; $i < 120; $i++) {
        RateLimiter::hit($key, 60);
    }

    $this->get('/?author=0', ['Accept' => 'application/activity+json'])->assertStatus(429);
    expect(app('inbound.hits')['actor'])->toBe(0);
});
