<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginPlaces;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('names placard’s area and Settings tab after enable', function (): void {
    PluginFixtures::use('placard');
    $found = app(PluginRegistry::class)->find('tests/placard');
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/placard',
        $found?->manifest->version() ?? '1.0.0',
        $found?->manifest->capabilities() ?? [],
        $found?->manifest->hosts() ?? [],
        $found?->manifest->hooks() ?? [],
        null,
    ));
    app(PluginLoader::class)->load();

    expect(app(PluginPlaces::class)->sentence('tests/placard'))
        ->toBe('its area is in the menu under Site › Ads and its settings are on its page’s Settings tab');
});

it('says hello runs in the background', function (): void {
    PluginFixtures::use('hello');
    $found = app(PluginRegistry::class)->find('tests/hello');
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/hello',
        $found?->manifest->version() ?? '1.0.0',
        $found?->manifest->capabilities() ?? [],
        $found?->manifest->hosts() ?? [],
        $found?->manifest->hooks() ?? [],
        null,
    ));

    expect(app(PluginPlaces::class)->sentence('tests/hello'))
        ->toBe('it runs in the background; there is nothing to open');
});
