<?php

/**
 * The statistics period select and the dates handed to the plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaPeriods;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginAreaScreen;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\AreaPeriod;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
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

it('defaults the overview to the last 30 days and lists the six periods', function (): void {
    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.id', AreaPeriod::LAST_30_DAYS)
            ->where('period.options.0.label', 'Last 7 days')
            ->where('period.options.1.label', 'Last 30 days')
            ->where('period.options.5.label', 'This year')
            ->has('period.range')
            ->has('period.compare'));
});

it('hands the chosen period’s dates to figures and charts', function (): void {
    PlacardArea::$on['figures'] = function (string $page, string $block, AreaContext $context): array {
        expect($context->period)->not->toBeNull()
            ->and($context->period?->id)->toBe(AreaPeriod::LAST_7_DAYS);

        return ['impressions' => Figure::number(12, 0.25)];
    };

    PlacardArea::$on['chart'] = function (string $page, string $block, AreaContext $context): Chart {
        expect($context->period?->id)->toBe(AreaPeriod::LAST_7_DAYS);

        return new Chart(
            ['2026-11-22', '2026-11-23'],
            ['impressions' => [1, 2]],
            ['impressions' => [0, 1]],
        );
    };

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'period' => AreaPeriod::LAST_7_DAYS]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('period.id', AreaPeriod::LAST_7_DAYS));

    // Deferred block props load through a partial reload in the browser; call data through the screen for the period.
    $plugin = app(PluginAreas::class)->find('placard');
    $screen = app(PluginAreaScreen::class);
    $figures = $screen->data($plugin, 'overview', 'totals', (string) $this->editor->id, [], AreaPeriod::LAST_7_DAYS);
    $chart = $screen->data($plugin, 'overview', 'daily', (string) $this->editor->id, [], AreaPeriod::LAST_7_DAYS);

    expect($figures['items'][0]['label'] ?? null)->toBe('Impressions')
        ->and($figures['items'][0]['change'] ?? null)->toBe(0.25)
        ->and($chart['series'][0]['dashed'] ?? false)->toBeFalse()
        ->and(collect($chart['series'])->firstWhere('dashed', true)['label'] ?? null)->toBe('The 7 days before');
});

it('keeps the plugin’s figure label when the period changes', function (): void {
    $plugin = app(PluginAreas::class)->find('placard');
    $figures = app(PluginAreaScreen::class)
        ->data($plugin, 'overview', 'totals', (string) $this->editor->id, [], AreaPeriod::THIS_YEAR);

    expect($figures['items'][0]['label'] ?? null)->toBe('Impressions')
        ->and($figures['items'][0]['thing'] ?? null)->toBe('impressions');
});

it('writes period dates in the person’s language', function (): void {
    app()->setLocale('fr');

    $shown = AreaPeriods::shown('2026-10-18', 'UTC', 'fr');

    expect($shown)->toContain('2026')
        ->and($shown)->not->toBe('Oct 18, 2026')
        ->and(AreaPeriods::byWeek(AreaPeriod::THIS_YEAR))->toBeTrue()
        ->and(AreaPeriods::byWeek(AreaPeriod::LAST_90_DAYS))->toBeTrue()
        ->and(AreaPeriods::byWeek(AreaPeriod::LAST_7_DAYS))->toBeFalse();
});

it('marks a figures block empty when the plugin returns no figures and declared empty', function (): void {
    PlacardArea::$on['figures'] = fn (): array => [];

    $plugin = app(PluginAreas::class)->find('placard');
    $data = app(PluginAreaScreen::class)
        ->data($plugin, 'overview', 'totals', (string) $this->editor->id);

    expect($data['empty'] ?? false)->toBeTrue()
        ->and($data['failed'])->toBeNull();
});

it('shows dashes for an empty figures() answer when the block declared no empty line', function (): void {
    $plugin = app(PluginAreas::class)->find('placard');
    $block = $plugin->area->page('overview')['blocks'][0];
    unset($block['empty']);
    PlacardArea::$on['figures'] = fn (): array => [];

    $data = app(AreaReads::class)->figures(
        $plugin,
        'overview',
        $block,
        AreaContexts::for('placard', (string) $this->editor->id),
    );

    expect($data['empty'] ?? false)->toBeFalse()
        ->and($data['items'][0]['value'])->toBeNull()
        ->and($data['items'])->not->toBeEmpty();
});
