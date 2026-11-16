<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\PublicRoutes\DeclaredRoute;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
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

it('answers 404 for a plugin the server switches off, by name or with *', function (string $switch): void {
    ($this->enable)();
    $this->get('/inbound/ping')->assertOk();

    config(['teme.plugins.disabled' => $switch]);
    app(PublicRouteRegistry::class)->flush();

    $this->get('/inbound/ping')->assertNotFound();
})->with(['by name' => 'tests/inbound', 'all' => '*']);

it('waits for consent before an update’s added route answers', function (): void {
    ($this->enable)();

    $wider = PluginFixtures::copy('inbound', [
        'version' => '1.1.0',
        'routes' => [
            ...PluginFixtures::manifest('inbound')['routes'],
            ['id' => 'extra', 'method' => 'GET', 'path' => '/{code}/extra', 'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Ping'],
        ],
    ]);
    PluginFixtures::use($wider);
    app(PublicRouteRegistry::class)->flush();

    $this->get('/inbound/extra')->assertNotFound();
    expect(($this->hits)('ping'))->toBe(0);
});

it('dispatches a conditional entry on any path, GET or POST, and falls through only when nothing matches', function (): void {
    $settings = PluginFixtures::manifest('inbound')['contributes']['settings'];
    app(SettingsRegistry::class)->group(DeclaredSettings::group('inbound', 'Inbound', 'Inbound secrets.', '1.0.0', $settings), 'tests/inbound');
    app(SettingsWriter::class)->write(new SettingsWrite('plugin.inbound', [], secrets: ['connection_token' => 'shared-secret']));

    $conditional = PluginFixtures::copy('inbound', [
        'routes' => [
            ...PluginFixtures::manifest('inbound')['routes'],
            ['id' => 'note', 'method' => 'GET', 'path' => '/{slug}', 'where' => ['slug' => '[a-z-]+'], 'when' => ['accept' => ['application/activity+json']], 'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Actor'],
            ['id' => 'inbox', 'method' => 'POST', 'path' => '/{slug}', 'where' => ['slug' => '[a-z-]+'], 'when' => ['query' => ['inbox' => '^1$']], 'handler' => 'Tests\\Fixtures\\Plugins\\Inbound\\Callback', 'verify' => ['kind' => 'secret', 'from' => 'header:X-Plugin-Token', 'secret' => 'connection_token']],
        ],
    ]);
    PluginFixtures::use($conditional);
    ($this->enable)();

    $this->get('/hello-world', ['Accept' => 'application/activity+json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/activity+json');
    expect(($this->hits)('actor'))->toBe(1);

    $this->post('/hello-world?inbox=1', [], ['X-Plugin-Token' => 'shared-secret'])->assertOk()->assertSee('ok', false);
    expect(($this->hits)('callback'))->toBe(1);

    $this->get('/hello-world', ['Accept' => 'text/html'])->assertNotFound();
    expect(($this->hits)('actor'))->toBe(1);
});

it('answers a POST that matches nothing with 405, never a page', function (string $path): void {
    ($this->enable)();

    $response = $this->post($path);

    expect($response->getStatusCode())->toBeIn([404, 405])
        ->and($response->headers->has('X-Teme-Template'))->toBeFalse();
})->with(['a post path' => '/hello-world', 'home' => '/', 'under the plugin' => '/inbound/nothing']);

it('answers HEAD for a declared GET', function (): void {
    ($this->enable)();

    $this->call('HEAD', '/inbound/ping')->assertOk();
    expect(($this->hits)('ping'))->toBe(1);
});

it('answers Teme’s error page when a handler throws', function (): void {
    ($this->enable)();

    $this->get('/inbound/throw')
        ->assertStatus(500)
        ->assertHeader('X-Teme-Template');
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
