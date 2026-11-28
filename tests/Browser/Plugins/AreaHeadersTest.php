<?php

/**
 * Record header, period select, tab counts and waiting-script banner (issue 768).
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
use App\Domain\Site\Insertions\Scripts\ScriptRequests;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\RecordHeader;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = PluginFixtures::manifest('placard');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();

    DB::table('placard_ads')->insert([
        'name' => 'Summer sale',
        'status' => 'active',
        'impressions' => 40,
        'data' => json_encode(['location' => 'between_items', 'versions' => [['label' => 'Wide', 'code' => '<div>Ad</div>', 'weight' => 1]], 'types' => [], 'terms' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->id = (string) DB::table('placard_ads')->where('name', 'Summer sale')->value('id');
    $this->actingAs($this->editor)->get('/admin/plugin/placard')->assertOk();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('draws the record header with status, meta, All ads and Delete ad', function (): void {
    PlacardArea::$on['header'] = fn (): RecordHeader => new RecordHeader(
        'Active',
        'ok',
        ['40 impressions in the last 30 days', 'last changed by Eve Editor'],
    );

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), "/admin/plugin/placard/records/ad/{$this->id}");
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');

    $page->assertSee('Summer sale')
        ->assertSee('Active')
        ->assertSee('40 impressions in the last 30 days')
        ->assertSee('last changed by Eve Editor')
        ->assertSee('All ads')
        ->assertSee('Delete ad');
    expect(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});

it('draws the period select, range line, Counting pill and waiting-script banner', function (): void {
    PlacardArea::$on['counts'] = fn (AreaContext $context): array => ['overview' => 0, 'ads' => 1];
    ScriptRequests::fromMarkup('placard', 'Ad: Ad code', '<script>adsbygoogle.push({})</script>', (string) $this->editor->id);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-period"]\') !== null');

    $page->assertSee('Period')
        ->assertSee('Last 30 days')
        ->assertSee('in the')
        ->assertSee('time zone')
        ->assertSee('Compared with')
        ->assertSee('1 script waits for approval. Until it’s approved, its ad shows without it.')
        ->assertSee('Review scripts');

    // Other tab shows Counting until counts arrive, then the number.
    cmWaitUntil($page, 'document.body.innerText.includes("Ads") && (document.body.innerText.includes("Counting") || document.body.innerText.match(/Ads\\s*1/))');
    cmNoJavaScriptErrors($page);
});
