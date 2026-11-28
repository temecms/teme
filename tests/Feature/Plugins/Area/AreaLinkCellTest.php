<?php

/**
 * Area table link cells: only a safe https address is drawn as a link.
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
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Lists\Cell;
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
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('hands a safe https link its host, and drops user, password or http as no link', function (string $href, ?string $kept): void {
    PlacardArea::$on['rows'] = fn (): RowPage => new RowPage([
        new Row('1', [
            'name' => Cell::text('Ad'),
            'position' => Cell::text('Between items on lists'),
            'status' => Cell::badge('Active', 'ok'),
            'report' => Cell::link('Open report', $href),
            'impressions' => Cell::number(1),
        ]),
    ], 1);

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_ads', fn (Assert $reload) => $reload
            ->where('block_ads.rows.0.cells.report', [
                'kind' => 'link',
                'value' => 'Open report',
                'href' => $kept,
                'host' => $kept === null ? null : 'ads.example',
            ])));
})->with([
    'https' => ['https://ads.example/report/1', 'https://ads.example/report/1'],
    'http' => ['http://ads.example/report/1', null],
    'with user' => ['https://user:pass@ads.example/report/1', null],
]);
