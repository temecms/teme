<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Status\Checks\ProviderChoiceGone;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->banner = fn () => app(ProviderChoiceGone::class)->banner();
});

afterEach(fn () => PluginFixtures::forget());

it('warns when a chosen provider is no longer enabled or installed', function (): void {
    DB::table('plugin_slot_choices')->insert(['slot' => PluginSlots::SEARCH, 'plugin' => 'tests/gone', 'chosen_by' => null, 'chosen_at' => now()]);

    $banner = ($this->banner)();

    expect($banner)->not->toBeNull()
        ->and($banner->level)->toBe('warning')
        ->and($banner->message)->toContain('Search');
});

it('says nothing while the chosen provider is enabled and installed', function (): void {
    PluginFixtures::use('slotted');
    $manifest = app(PluginRegistry::class)->find('tests/slotted')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/slotted', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    DB::table('plugin_slot_choices')->insert(['slot' => PluginSlots::IMAGE_SERVICE, 'plugin' => 'tests/slotted', 'chosen_by' => null, 'chosen_at' => now()]);

    expect(($this->banner)())->toBeNull();
});
