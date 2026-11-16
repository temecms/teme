<?php

declare(strict_types=1);

use App\Domain\Cache\PublicChanges;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A declared route's handler built the way the plugin's own class is
 * (docs/plans/plugin-sockets-1.md, A-W13): with only what its constructor
 * asks for and Teme offers plugins, by the same capability rules.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->routes = fn (array $routes, array $capabilities = ['http', 'feeds.links', 'routes.public']): string => PluginFixtures::copy('hub', [
        'capabilities' => $capabilities,
        'routes' => $routes,
    ]);
    $this->route = fn (string $id, string $handler): array => ['id' => $id, 'method' => 'GET', 'path' => '/{code}/'.$id, 'handler' => 'Tests\\Fixtures\\Plugins\\Hub\\'.$handler];
});

afterEach(fn () => PluginFixtures::forget());

it('gives a handler the settings reader and the site’s facts it asks for, and builds one with no constructor as before', function (): void {
    PluginFixtures::use(($this->routes)([($this->route)('page', 'HubPage'), ($this->route)('plain', 'Plain')]));
    $manifest = app(PluginRegistry::class)->find('tests/hub')->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/hub', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
    app(PluginLoader::class)->load();
    app(PublicRouteRegistry::class)->flush();

    $changes = app(PublicChanges::class);
    $changes->open();
    DB::transaction(fn (): int => app(SettingsWriter::class)->write(new SettingsWrite('general', ['site_title' => 'Harbour Times'])));
    $changes->close();

    $this->get('/hub/page')->assertOk()->assertSeeText('Harbour Times https://hub.example.com/');
    $this->get('/hub/plain')->assertOk()->assertSeeText('plain');
});

it('refuses a handler that asks for a door its plugin doesn’t ask for', function (): void {
    PluginFixtures::use(($this->routes)([($this->route)('jobs', 'WantsJobs')]));

    expect(app(PluginRegistry::class)->find('tests/hub')?->problems)->toContain('Tests\\Fixtures\\Plugins\\Hub\\WantsJobs asks for jobs, but the plugin doesn’t ask for jobs.');
});
