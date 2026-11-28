<?php

/**
 * Tab counts on a plugin area: deferred counts() for other tabs.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Admin\AreaContext;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    DB::table('placard_ads')->insert([
        'name' => 'Summer sale',
        'status' => 'active',
        'impressions' => 10,
        'data' => json_encode(['location' => 'between_items']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('defers page counts from the plugin’s counts()', function (): void {
    PlacardArea::$on['counts'] = fn (AreaContext $context): array => ['overview' => 0, 'ads' => 7];

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('counts', fn (Assert $reload) => $reload
                ->where('counts', ['overview' => 0, 'ads' => 7])));
});

it('omits a count that is not an integer or overruns the cap', function (): void {
    PlacardArea::$on['counts'] = fn (): array => ['overview' => 1.5, 'ads' => 1_000_001, 'unknown' => 3];

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('counts', fn (Assert $reload) => $reload->where('counts', [])));
});

it('omits counts when the plugin throws', function (): void {
    PlacardArea::$on['counts'] = function (): never {
        throw new RuntimeException('counts broken');
    };

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('counts', fn (Assert $reload) => $reload->where('counts', null)));
});
