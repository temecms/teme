<?php

/**
 * Tab counts on a plugin area: deferred counts() for other tabs.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\PluginAreas;
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
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\HasAreaCounts;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Admin\TableQuery;
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

it('answers no counts when the area data is not HasAreaCounts', function (): void {
    $plugin = app(PluginAreas::class)->find('placard');
    expect($plugin)->not->toBeNull();

    $plain = new AreaPlugin($plugin->name, $plugin->code, $plugin->label, $plugin->area, new class implements PluginArea
    {
        public function figures(string $page, string $block, AreaContext $context): array
        {
            return [];
        }

        public function chart(string $page, string $block, AreaContext $context): Chart
        {
            return new Chart([], []);
        }

        public function rows(string $record, TableQuery $query, AreaContext $context): RowPage
        {
            return new RowPage([], 0);
        }

        public function values(string $record, string $id, AreaContext $context): ?array
        {
            return null;
        }

        public function save(string $record, ?string $id, array $values, AreaContext $context): Saved
        {
            return new Saved('1');
        }

        public function act(string $record, string $action, array $ids, AreaContext $context): Saved
        {
            return new Saved('1');
        }
    });

    expect($plain->data instanceof HasAreaCounts)->toBeFalse()
        ->and(app(AreaReads::class)->counts($plain, AreaContexts::for('placard', (string) $this->editor->id)))->toBeNull();
});
