<?php

declare(strict_types=1);

use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Dashboard\Queries\Dashboard;
use App\Domain\Plugins\Area\AreaValidator;
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
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->contributor = User::factory()->create()->assignRole('contributor');

    $this->start = function (): void {
        PluginFixtures::use('placard');
        $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
        app(PluginLoader::class)->load();
    };
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('shows a person with the area permission the figures and chart of the plugin\'s Home panel', function (): void {
    ($this->start)();

    $panel = dashPanel($this->editor, 'plugin.placard.ads');

    expect($panel)->not->toBeNull()
        ->and($panel['failed'])->toBeFalse()
        ->and(array_column($panel['blocks'], 'id'))->toBe(['totals', 'daily', 'hint'])
        ->and($panel['blocks'][0]['data']['items'][0]['id'])->toBe('impressions')
        ->and($panel['blocks'][1]['data']['labels'])->toHaveCount(3)
        ->and($panel['blocks'][2]['text'])->toBe('Ads show to readers once they are active.');
});

it('keeps the plugin\'s panel off Home for a person who lacks the area permission', function (): void {
    ($this->start)();

    expect(dashKeys($this->contributor))->not->toContain('plugin.placard.ads')
        ->and(array_column(app(Dashboard::class)->forUser($this->contributor)['catalogue'], 'key'))->not->toContain('plugin.placard.ads');
});

it('marks the panel failed when every block that reads data fails, and says so in each block', function (): void {
    ($this->start)();
    PlacardArea::$on['figures'] = fn (): never => throw new RuntimeException('The ad server is down.');
    PlacardArea::$on['chart'] = fn (): never => throw new RuntimeException('The ad server is down.');

    $panel = dashPanel($this->editor, 'plugin.placard.ads');

    expect($panel['failed'])->toBeTrue()
        ->and($panel['blocks'][0]['data']['failed'])->toBeString()
        ->and($panel['blocks'][1]['data']['failed'])->toBeString()
        // The notice reads nothing, so it still shows.
        ->and($panel['blocks'][2]['text'])->toBe('Ads show to readers once they are active.');
});

it('registers the panel as the plugin\'s own, sized as it declares', function (): void {
    ($this->start)();

    $entry = collect(app(DashboardRegistry::class)->all())->firstWhere('key', 'plugin.placard.ads');

    expect($entry)->not->toBeNull()
        ->and($entry->kind)->toBe('plugin')
        ->and($entry->owner)->toBe('tests/placard')
        ->and($entry->size)->toBe('medium');
});

it('leaves out the panel of a plugin that is not enabled', function (): void {
    expect(collect(app(DashboardRegistry::class)->all())->pluck('key')->all())->not->toContain('plugin.placard.ads')
        ->and(dashKeys($this->editor))->not->toContain('plugin.placard.ads');
});

it('hides the panel when the person hides it, and moves it when they move it', function (): void {
    ($this->start)();

    lscrPrefer($this->editor, ['dashboard.hidden' => ['plugin.placard.ads']]);

    expect(dashKeys($this->editor))->not->toContain('plugin.placard.ads')
        ->and(app(Dashboard::class)->forUser($this->editor)['layout']['hidden'])->toBe(['plugin.placard.ads']);

    lscrPrefer($this->editor, ['dashboard.hidden' => [], 'dashboard.order' => ['plugin.placard.ads']]);

    expect(dashKeys($this->editor))->toContain('plugin.placard.ads')
        ->and(app(Dashboard::class)->forUser($this->editor)['layout']['order'][0])->toBe('plugin.placard.ads');
});

it('refuses a Home panel that names a page the area lacks, a block of another page, or a block Home cannot show', function (): void {
    $area = [
        'pages' => [[
            'id' => 'overview',
            'label' => 'Overview',
            'blocks' => [
                ['type' => 'figures', 'id' => 'totals', 'items' => []],
                ['type' => 'actions', 'id' => 'upkeep', 'actions' => []],
            ],
        ]],
    ];
    $validator = app(AreaValidator::class);

    expect($validator->homePanelErrors([['id' => 'ads', 'title' => 'Ads', 'page' => 'overview', 'blocks' => ['totals']]], $area))->toBe([])
        ->and($validator->homePanelErrors([['id' => 'ads', 'title' => 'Ads', 'page' => 'nowhere', 'blocks' => ['totals']]], $area))->toContain('A Home panel must name a page of its area.')
        ->and($validator->homePanelErrors([['id' => 'ads', 'title' => 'Ads', 'page' => 'overview', 'blocks' => ['missing']]], $area))->toContain('A Home panel can only show blocks of its own page.')
        ->and($validator->homePanelErrors([['id' => 'ads', 'title' => 'Ads', 'page' => 'overview', 'blocks' => ['upkeep']]], $area))->toContain('A Home panel can’t show a actions block.')
        ->and($validator->homePanelErrors([['id' => 'ads', 'title' => 'Ads', 'page' => 'overview', 'blocks' => ['totals'], 'columns' => []]], $area))->toContain('columns isn’t something this part of an area can have.')
        ->and($validator->homePanelErrors([['id' => 'a', 'title' => 'A', 'page' => 'overview', 'blocks' => ['totals']], ['id' => 'b', 'title' => 'B', 'page' => 'overview', 'blocks' => ['totals']], ['id' => 'c', 'title' => 'C', 'page' => 'overview', 'blocks' => ['totals']], ['id' => 'd', 'title' => 'D', 'page' => 'overview', 'blocks' => ['totals']]], $area))->toBe(['A plugin adds 1 to 3 panels to Home.']);
});
