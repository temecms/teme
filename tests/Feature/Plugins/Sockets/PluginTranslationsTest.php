<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A plugin's own words, from its lang/<locale>.json (docs/plans/plugin-sockets-1.md,
 * A-W14): loaded for an enabled plugin, core's own words winning a clash.
 */
beforeEach(function (): void {
    PluginFixtures::use('hub');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    $this->enable = function (): void {
        $manifest = PluginFixtures::manifest('hub');
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/hub', $manifest['version'], $manifest['capabilities'], $manifest['hosts'], [], null));
    };
});

afterEach(fn () => PluginFixtures::forget());

it('translates an enabled plugin’s sentence in its Spanish words, and keeps core’s own in a clash', function (): void {
    ($this->enable)();
    app(PluginLoader::class)->load();
    app()->setLocale('es');

    expect(__('Hub sent the ping.'))->toBe('El hub envió el ping.')
        ->and(__('All rights reserved.'))->toBe(json_decode((string) file_get_contents(lang_path('es.json')), true)['All rights reserved.']);
});

it('falls back to the English sentence in a language the plugin has no file for', function (): void {
    ($this->enable)();
    app(PluginLoader::class)->load();
    app()->setLocale('fr');

    expect(__('Hub sent the ping.'))->toBe('Hub sent the ping.');
});

it('loads no words of a plugin that isn’t enabled', function (): void {
    app(PluginLoader::class)->load();
    app()->setLocale('es');

    expect(__('Hub sent the ping.'))->toBe('Hub sent the ping.');
});
