<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\DeclaredRoute;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;
use Tests\Fixtures\Plugins\PluginFixtures;

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

    $this->hits = fn (string $key): int => (int) (app('inbound.hits')[$key] ?? 0);
});

afterEach(fn () => PluginFixtures::forget());

it('answers an enabled granted plugin’s GET under its code', function (): void {
    ($this->enable)();

    $this->get('/inbound/ping')
        ->assertOk()
        ->assertSee('pong', false);

    expect(($this->hits)('ping'))->toBe(1);
});

it('answers 404 when the plugin is not granted routes.public', function (): void {
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/inbound',
        'code' => 'inbound',
        'version' => '1.0.0',
        'consent' => ['capabilities' => [], 'hosts' => [], 'hooks' => []],
    ]);
    app(PublicRouteRegistry::class)->flush();

    $this->get('/inbound/ping')->assertNotFound();
});

it('answers 404 when the plugin is disabled', function (): void {
    PluginRecord::factory()->disabled()->create([
        'name' => 'tests/inbound',
        'code' => 'inbound',
        'version' => '1.0.0',
        'consent' => ['capabilities' => ['routes.public'], 'hosts' => [], 'hooks' => []],
    ]);
    app(PublicRouteRegistry::class)->flush();

    $this->get('/inbound/ping')->assertNotFound();
});

it('reaches the actor handler for /?author=0 with the activity Accept, and leaves the home page alone otherwise', function (): void {
    ($this->enable)();

    $this->get('/', ['Accept' => 'application/activity+json'])
        ->assertOk();

    expect(($this->hits)('actor'))->toBe(0);

    $this->get('/?author=0', ['Accept' => 'text/html'])
        ->assertOk();

    expect(($this->hits)('actor'))->toBe(0);

    $this->get('/?author=0', ['Accept' => 'application/activity+json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/activity+json')
        ->assertJson(['type' => 'Person', 'id' => '0']);

    expect(($this->hits)('actor'))->toBe(1);
});

it('lets a core-owned entry win over an overlapping plugin entry', function (): void {
    ($this->enable)();

    app(PublicRouteRegistry::class)->registerCore(new DeclaredRoute(
        owner: DeclaredRoute::OWNER_CORE,
        id: 'actor',
        method: 'GET',
        path: '/',
        handler: CoreActorStub::class,
        when: ['query' => ['author' => '^[0-9]+$'], 'accept' => ['application/activity+json']],
    ));

    $this->get('/?author=7', ['Accept' => 'application/activity+json'])
        ->assertOk()
        ->assertSee('core-actor', false);

    expect(($this->hits)('actor'))->toBe(0);
});

it('loads no plugin entry in safe mode', function (): void {
    ($this->enable)();

    PluginFixtures::serving('admin/plugins', function (): void {
        app(PublicRouteRegistry::class)->flush();

        expect(app(PublicRouteRegistry::class)->match('GET', '/inbound/ping'))->toBeNull();
    });
});

/**
 * Core stub that wins over the fixture's actor in one test.
 */
final class CoreActorStub implements PublicRoute
{
    public function handle(PublicRequest $request): PublicResponse
    {
        return new PublicResponse(200, 'text/plain; charset=UTF-8', 'core-actor');
    }
}
