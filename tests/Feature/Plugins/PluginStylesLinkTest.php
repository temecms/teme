<?php

/**
 * Tests that `teme:plugins:link` copies an enabled plugin's declared
 * stylesheets into public/plugins, and removes them once the plugin is
 * disabled.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->restart = function (): void {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
});

afterEach(function (): void {
    File::deleteDirectory(public_path('plugins/cardbox'));
    PluginFixtures::forget();
});

it('copies an enabled plugin’s declared stylesheet into public/plugins, and removes it when disabled', function (): void {
    PluginFixtures::use('cardbox');
    $manifest = app(PluginRegistry::class)->all()['tests/cardbox']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/cardbox', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null));
    ($this->restart)();

    $this->artisan('teme:plugins:link')->assertSuccessful();
    expect(public_path('plugins/cardbox/dist/note.css'))->toBeFile();

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/cardbox', null));
    ($this->restart)();

    $this->artisan('teme:plugins:link')->assertSuccessful();
    expect(public_path('plugins/cardbox'))->not->toBeDirectory();
});
