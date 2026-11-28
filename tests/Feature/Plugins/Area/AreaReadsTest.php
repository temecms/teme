<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\AreaTooSlow;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Columns\ColumnClock;
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
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
use Teme\Contracts\Admin\PluginArea;
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
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');

    $this->faults = new class implements ListenerFaults
    {
        /** @var list<array{string, string, string, Throwable}> */
        public array $seen = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->seen[] = [$owner, $id, $hook, $failure];
        }
    };
    app()->instance(ListenerFaults::class, $this->faults);

    $this->clock = new class implements ColumnClock
    {
        public int $now = 0;

        /** @var list<int> */
        public array $steps = [];

        public function nanoseconds(): int
        {
            if ($this->steps !== []) {
                $this->now += array_shift($this->steps);
            }

            return $this->now;
        }
    };
    app()->instance(ColumnClock::class, $this->clock);

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    DB::table('placard_ads')->insert([
        ['name' => 'Sponsor banner', 'status' => 'active', 'impressions' => 1200, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Winter sale', 'status' => 'paused', 'impressions' => 300, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->overview = fn (User $person) => $this->actingAs($person)->get(route('plugins.area.show', ['code' => 'placard']));
    $this->ads = fn (User $person, array $ask = []) => $this->actingAs($person)->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads', ...($ask === [] ? [] : ['t' => ['ads' => $ask]])]));
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('draws the area’s page under the plugin’s own name, its blocks as declared, each block’s data deferred', function (): void {
    ($this->overview)($this->editor)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('plugins/area')
        ->where('plugin', ['code' => 'placard', 'icon' => null, 'name' => 'Placard'])
        ->where('area.label', 'Ads')
        ->where('tabs', [['id' => 'overview', 'label' => 'Overview', 'current' => true], ['id' => 'ads', 'label' => 'Ads', 'current' => false]])
        ->where('blocks.0.type', 'figures')
        ->where('blocks.3', ['id' => 'hint', 'type' => 'notice', 'text' => 'Ads show to readers once they are active.', 'tone' => 'info'])
        ->missing('block_totals')
        ->missing('block_daily'));

    expect(PlacardArea::$calls)->toBe([]);
});

it('hands the figures and the chart to the page, cleaned', function (): void {
    ($this->overview)($this->editor)->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(['block_totals', 'block_daily'], fn (Assert $reload) => $reload
            ->where('block_totals', ['items' => [
                ['id' => 'impressions', 'label' => 'Impressions, last 30 days', 'format' => 'number', 'value' => 1500, 'change' => 0.12, 'thing' => 'impressions', 'before' => 'the 30 days before'],
                ['id' => 'ctr', 'label' => 'Click rate', 'format' => 'percent', 'value' => 0.031, 'change' => -0.5, 'thing' => 'click rate', 'before' => 'the 30 days before'],
            ], 'failed' => null])
            ->where('block_daily', ['labels' => ['2026-11-01', '2026-11-02', '2026-11-03'], 'series' => [
                ['id' => 'impressions', 'label' => 'Impressions', 'values' => [10, 20, 15], 'dashed' => false],
                ['id' => 'clicks', 'label' => 'Clicks', 'values' => [1, 3, 2], 'dashed' => false],
            ], 'failed' => null])));
});

it('hands a table its rows as the person asks: search, filter, sort and page', function (): void {
    ($this->ads)($this->editor, ['search' => 'sale', 'filters' => ['status' => 'paused', 'nope' => 'x'], 'sort' => 'impressions', 'direction' => 'asc', 'page' => '1'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('asks.ads.search', 'sale')
            ->loadDeferredProps('block_ads', fn (Assert $reload) => $reload
                ->where('block_ads.total', 1)
                ->where('block_ads.rows.0.id', '2')
                ->where('block_ads.rows.0.cells', [
                    'name' => ['kind' => 'text', 'value' => 'Winter sale'],
                    'position' => ['kind' => 'text', 'value' => 'Between items on lists'],
                    'status' => ['kind' => 'badge', 'value' => 'Paused', 'tone' => 'neutral'],
                    'report' => ['kind' => 'link', 'value' => 'Open report', 'href' => 'https://ads.example/report/2', 'host' => 'ads.example'],
                    'impressions' => ['kind' => 'number', 'value' => 300],
                ])
                ->where('block_ads.rows.0.unavailable', ['disable', 'check-code'])));

    $query = PlacardArea::$calls[0][1][1];

    expect([$query->page, $query->perPage, $query->search, $query->filters, $query->sort, $query->direction])
        ->toBe([1, 20, 'sale', ['status' => 'paused'], 'impressions', 'asc']);
});

it('holds a table’s ask to what it declares', function (): void {
    ($this->ads)($this->editor, ['search' => str_repeat('a', 300), 'sort' => 'name', 'page' => '99999'])
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_ads', fn (Assert $reload) => $reload->has('block_ads')));

    $query = PlacardArea::$calls[0][1][1];

    expect([$query->page, mb_strlen($query->search), $query->sort])->toBe([1_000, 100, null]);
});

it('hands markup over as text, never as markup', function (): void {
    // As round 1's lists do (CellText): the characters, kept, for the page to draw as text.
    PlacardArea::$on['rows'] = fn (): RowPage => new RowPage([new Row('1', ['name' => Cell::text('<b>Bold</b> <script>x()</script>ad')])], 1);
    PlacardArea::$on['figures'] = fn (): array => ['impressions' => Figure::number(5), 'ctr' => Figure::percent(0.5)];

    ($this->ads)($this->editor)->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_ads', fn (Assert $reload) => $reload
        ->where('block_ads.rows.0.cells.name', ['kind' => 'text', 'value' => '<b>Bold</b> <script>x()</script>ad'])));
});

it('shows a block’s failure line, and records the fault, when the plugin throws or runs slow', function (): void {
    PlacardArea::$on['figures'] = fn (): array => throw new RuntimeException('The stats table is locked.');
    PlacardArea::$on['chart'] = function (): Chart {
        $this->clock->steps = [300_000_000];

        return new Chart(['2026-11-01'], ['impressions' => [1], 'clicks' => [0]]);
    };

    ($this->overview)($this->editor)->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('block_totals', fn (Assert $reload) => $reload->where('block_totals.failed', 'This part of Placard couldn’t be shown this time.'))
        ->loadDeferredProps('block_daily', fn (Assert $reload) => $reload->where('block_daily.failed', 'This part of Placard couldn’t be shown this time.')));

    expect($this->faults->seen)->toHaveCount(2)
        ->and($this->faults->seen[0][0])->toBe('tests/placard')
        ->and($this->faults->seen[0][1])->toBe('tests/placard#area')
        ->and($this->faults->seen[0][2])->toBe(PluginArea::class)
        ->and($this->faults->seen[0][3]->getMessage())->toBe('The stats table is locked.')
        ->and($this->faults->seen[1][3])->toBeInstanceOf(AreaTooSlow::class);
});

it('stops calling once the page’s budget is spent', function (): void {
    $plugin = app(PluginAreas::class)->find('placard');
    $reads = app(AreaReads::class);
    $context = AreaContexts::for('placard', (string) $this->editor->id);
    $block = $plugin?->area->block('overview', 'totals') ?? [];

    foreach (range(1, 5) as $call) {
        $this->clock->steps = [0, 240_000_000];
        $reads->figures($plugin, 'overview', $block, $context);
    }

    expect(count(PlacardArea::$calls))->toBe(5);

    $this->clock->steps = [0, 1];
    $read = $reads->figures($plugin, 'overview', $block, $context);

    expect($read['failed'])->toBe('This part of Placard couldn’t be shown this time.')
        ->and(count(PlacardArea::$calls))->toBe(5)
        ->and($this->faults->seen[0][3])->toBeInstanceOf(AreaTooSlow::class);
});

it('refuses a person without the area’s permission, never calling the plugin', function (): void {
    $author = User::factory()->create()->assignRole('author');

    ($this->overview)($author)->assertForbidden();
    ($this->ads)($author, ['page' => '1'])->assertForbidden();

    expect(PlacardArea::$calls)->toBe([]);
});

it('answers 404 for a plugin with no area, or a page it doesn’t declare', function (): void {
    $this->actingAs($this->admin)->get(route('plugins.area.show', ['code' => 'nothing']))->assertNotFound();
    $this->actingAs($this->admin)->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'nowhere']))->assertNotFound();
});
