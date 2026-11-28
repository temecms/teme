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
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

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

it('draws status only when the header has a pill and no meta', function (): void {
    PlacardArea::$on['header'] = fn (): RecordHeader => new RecordHeader('Paused', 'neutral', []);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), "/admin/plugin/placard/records/ad/{$this->id}");
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-header-meta"]\') !== null');

    $page->assertSee('Paused')
        ->assertDontSee('impressions in the last 30 days')
        ->assertScript(<<<'JS'
            () => {
                const meta = document.querySelector('[data-test="record-header-meta"]');
                return meta !== null
                    && (meta.textContent ?? '').includes('Paused')
                    && !(meta.textContent ?? '').includes('·');
            }
            JS);
    cmNoJavaScriptErrors($page);
});

it('keeps title only when the plugin supplies no record header', function (): void {
    PlacardArea::$on['header'] = fn (): ?RecordHeader => null;

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), "/admin/plugin/placard/records/ad/{$this->id}");
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');

    $page->assertSee('Summer sale')
        ->assertDontSee('40 impressions in the last 30 days')
        ->assertNotPresent('[data-test="record-header-meta"]');
    cmNoJavaScriptErrors($page);
});

it('draws the period select and range line on overview', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-period"]\') !== null');

    $page->assertSee('Period')
        ->assertSee('Last 30 days')
        ->assertSee('in the')
        ->assertSee('time zone')
        ->assertSee('Compared with');
    cmNoJavaScriptErrors($page);
});

it('shows Counting on the way while deferred tab counts load', function (): void {
    PlacardArea::$on['counts'] = function (AreaContext $context): array {
        // Slow enough that the Counting pill is painted before counts arrive.
        usleep(400_000);

        return ['overview' => 0, 'ads' => 1];
    };

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="tab-counting"]\') !== null');

    $page->assertScript(<<<'JS'
        () => {
            const pill = document.querySelector('[data-test="tab-counting"]');
            const name = pill?.querySelector('.sr-only');

            return (name?.textContent ?? '').includes('Counting');
        }
        JS);
    cmNoJavaScriptErrors($page);
});

it('shows the Ads tab count once deferred counts arrive', function (): void {
    PlacardArea::$on['counts'] = fn (AreaContext $context): array => ['overview' => 0, 'ads' => 1];

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, '/Ads\\s*1/.test(document.body.innerText)');

    $page->assertScript(<<<'JS'
        () => document.querySelector('[data-test="tab-counting"]') === null
            && /Ads\s*1/.test(document.body.innerText)
        JS);
    cmNoJavaScriptErrors($page);
});

it('shows no Counting pill when deferred counts fail', function (): void {
    PlacardArea::$on['counts'] = function (): never {
        throw new RuntimeException('counts broken');
    };

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-period"]\') !== null');
    cmWaitUntil($page, <<<'JS'
        (() => {
            if (document.querySelector('[data-test="tab-counting"]') !== null) {
                return false;
            }
            const ads = [...document.querySelectorAll('[data-slot="in-page-nav"] a')]
                .find((link) => (link.textContent ?? '').includes('Ads'));
            return ads !== undefined && !/Ads\s*\d/.test(ads.textContent ?? '');
        })()
        JS);

    cmNoJavaScriptErrors($page);
});

it('shows Loading the new period… and skeletons, then the Last 7 days range', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'#area-period\') !== null');

    // Catch the brief Loading… + skeleton flash even when the request is fast.
    $page->script(<<<'JS'
        () => {
            window.__seenPeriodLoading = false;
            const watch = () => {
                if (document.body.innerText.includes('Loading the new period…')
                    && document.querySelector('[data-test="area-skeleton"]') !== null) {
                    window.__seenPeriodLoading = true;
                }
            };
            watch();
            window.__periodLoadingObs = new MutationObserver(watch);
            window.__periodLoadingObs.observe(document.body, { childList: true, subtree: true, characterData: true });
        }
        JS);

    $page->click('#area-period');
    cmWaitUntil($page, 'document.querySelector(\'[role="option"]\') !== null');
    $page->click('[role="option"] >> text="Last 7 days"');

    cmWaitUntil($page, 'window.__seenPeriodLoading === true');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-period-range"]\')?.textContent?.includes("Compared with") === true && !document.body.innerText.includes("Loading the new period…")');

    $page->assertSee('Last 7 days')
        ->assertSee('Compared with')
        ->assertScript('document.activeElement?.id === "area-period" || document.activeElement?.closest("#area-period") !== null || document.getElementById("area-period")?.contains(document.activeElement)');
    cmNoJavaScriptErrors($page);
});

it('draws No statistics yet when figures are empty', function (): void {
    PlacardArea::$on['figures'] = fn (): array => [];

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-figures-empty"]\') !== null');

    $page->assertSee('No statistics yet')
        ->assertSee('Figures appear the day after an ad first shows.');
    cmNoJavaScriptErrors($page);
});

it('draws a failed figures block with Try again', function (): void {
    PlacardArea::$on['figures'] = function (): array {
        throw new RuntimeException('boom');
    };

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.body.innerText.includes("couldn’t be shown")');

    $page->assertSee('This part of Placard couldn’t be shown this time.')
        ->assertSee('Try again');
    cmNoJavaScriptErrors($page);
});

it('draws the waiting-script banner on the ads list with Review scripts for an administrator', function (): void {
    ScriptRequests::fromMarkup('placard', 'Ad: Ad code', '<script>adsbygoogle.push({})</script>', (string) $this->admin->id);

    $page = cmNavigate(cmSignIn($this->admin)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-scripts-waiting"]\') !== null');

    $page->assertSee('1 script waits for approval. Until it’s approved, its ad shows without it.')
        ->assertSee('Review scripts')
        ->assertPresent('[data-test="area-review-scripts"]');
    cmNoJavaScriptErrors($page);
});

it('draws several waiting scripts without Review scripts for an editor', function (): void {
    ScriptRequests::fromMarkup('placard', 'Ad: One', '<script>one()</script>', (string) $this->editor->id);
    ScriptRequests::fromMarkup('placard', 'Ad: Two', '<script>two()</script>', (string) $this->editor->id);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-scripts-waiting"]\') !== null');

    $page->assertSee('2 scripts wait for approval. Until they’re approved, their ads show without them.')
        ->assertDontSee('Review scripts')
        ->assertNotPresent('[data-test="area-review-scripts"]');
    cmNoJavaScriptErrors($page);
});

it('hides the waiting-script banner when none wait', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-table"]\') !== null || document.querySelector(\'[data-test="area-blocks"]\') !== null');

    $page->assertNotPresent('[data-test="area-scripts-waiting"]');
    cmNoJavaScriptErrors($page);
});
