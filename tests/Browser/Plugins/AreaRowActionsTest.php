<?php

/**
 * Custom row actions and link cells on a plugin area table (issue 465).
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
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Lists\Cell;
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
    Queue::fake();
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = PluginFixtures::manifest('placard');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();

    foreach ([['Ad 01', 'active', 40], ['Ad 02', 'paused', 0], ['Ad 03', 'active', 0]] as [$name, $status, $impressions]) {
        DB::table('placard_ads')->insert([
            'name' => $name,
            'status' => $status,
            'impressions' => $impressions,
            'data' => json_encode(['location' => 'between_items', 'versions' => [['label' => 'Wide', 'code' => '<div>Ad</div>', 'weight' => 1]], 'types' => [], 'terms' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');
    $this->actingAs($this->editor)->get('/admin/plugin/placard/ads')->assertOk();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('orders custom actions before Enable/Disable/Delete and shows their icons', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="area-table"] tbody tr\').length === 3');

    $page->click('[data-test="area-table"] tbody tr:has-text("Ad 01") [data-test="row-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');

    expect($page->script(<<<'JS'
        () => [...document.querySelectorAll('[role="menu"] [data-action]')].map((el) => el.dataset.action).join('|')
        JS))->toBe('check-code|reset-stats|disable|delete')
        ->and($page->script(<<<'JS'
            () => {
                const check = document.querySelector('[role="menu"] [data-action="check-code"] svg');
                const reset = document.querySelector('[role="menu"] [data-action="reset-stats"] svg');
                return check !== null && reset !== null;
            }
            JS))->toBeTrue();
    cmNoJavaScriptErrors($page);
});

it('draws the network report link, custom row actions, confirm, and queued pill with the exact words', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="area-table"] tbody tr\').length === 3');

    $page->assertSee('Network report')
        ->assertSee('Open report')
        ->assertScript(<<<'JS'
            () => {
                const link = document.querySelector('[data-test="area-table"] a[href^="https://ads.example/report/"]');
                const host = link?.nextElementSibling;
                return link !== null
                    && link.getAttribute('target') === '_blank'
                    && link.getAttribute('rel') === 'noopener noreferrer'
                    && (link.textContent ?? '').includes('opens ads.example in a new tab and leaves this site')
                    && host?.getAttribute('aria-hidden') === 'true'
                    && (host?.textContent ?? '').trim() === 'ads.example';
            }
            JS);

    // Active ad with impressions: Check and Reset in the menu.
    $page->click('[data-test="area-table"] tbody tr:has-text("Ad 01") [data-test="row-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');
    $page->assertSee('Check the ad code')
        ->assertSee('Reset statistics…')
        ->assertSee('Disable')
        ->assertSee('Delete');
    expect(cmAxeViolations($page))->toBe([]);

    $page->click('[role="menu"] [data-action="check-code"]');
    ($this->seen)($page, 'Check queued for “Ad 01”. Its status updates when Placard has finished.');
    ($this->seen)($page, 'Check queued');

    // Confirming Reset.
    $page->click('[data-test="area-table"] tbody tr:has-text("Ad 01") [data-test="row-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');
    $page->click('[role="menu"] [data-action="reset-stats"]');
    shellSettled($page, '[role="dialog"]');
    $page->assertSee('Reset the statistics for “Ad 01”?')
        ->assertSee('Its impressions and clicks go back to zero for every period. This can’t be undone.')
        ->assertSee('Keep them')
        ->assertSee('Reset statistics');
    $page->click('[role="dialog"] button:has-text("Reset statistics")');
    ($this->seen)($page, 'Reset queued for “Ad 01”. Its figures show zero once Placard has finished.');

    // Paused ad: no Check the ad code.
    $page->click('[data-test="area-table"] tbody tr:has-text("Ad 02") [data-test="row-menu"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');
    $page->assertDontSee('Check the ad code')
        ->assertDontSee('Reset statistics…')
        ->assertSee('Enable');
    $page->keys('[role="menu"]', 'Escape');
    cmNoJavaScriptErrors($page);
});

it('draws the ads table as cards on a phone: report as body, facts, and Edit under the name', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-slot="list-card"]\').length === 3');

    $page->assertScript(<<<'JS'
        () => {
            const card = document.querySelector('[data-slot="list-card"]');
            const facts = card?.querySelector('[data-card="facts"]');
            const body = card?.querySelector('[data-card="body"]');
            const title = card?.querySelector('[data-card="title"]');
            const text = title?.textContent ?? '';

            return card !== null
                && facts?.getAttribute('data-card') === 'facts'
                && (facts?.textContent ?? '').includes('Between items on lists')
                && (facts?.textContent ?? '').includes('Active')
                && body !== null
                && (body.textContent ?? '').includes('Open report')
                && text.includes('Edit')
                && text.includes('Duplicate')
                && card.querySelector('[data-test="row-menu"]') !== null;
        }
        JS);

    // Menu opens as a sheet from the foot.
    $page->click('[data-slot="list-card"]:has-text("Ad 01") [data-test="row-menu"]');
    shellSettled($page, '[data-slot="sheet-content"]');
    $page->assertScript(<<<'JS'
        () => {
            const sheet = document.querySelector('[data-slot="sheet-content"]');
            return sheet !== null
                && sheet.querySelector('[role="menu"]') !== null
                && (sheet.textContent ?? '').includes('Check the ad code')
                && (sheet.textContent ?? '').includes('Disable');
        }
        JS);
    cmNoJavaScriptErrors($page);
});

it('draws No report link when a row’s report is not https', function (): void {
    PlacardArea::$on['rows'] = fn (): RowPage => new RowPage([
        new Row('1', [
            'name' => Cell::text('No link ad'),
            'position' => Cell::text('Between items on lists'),
            'status' => Cell::badge('Active', 'ok'),
            'report' => Cell::link('Open report', 'http://ads.example/report/1'),
            'impressions' => Cell::number(0),
        ], ['enable', 'reset-stats']),
    ], 1);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.body.innerText.includes("No report link")');

    $page->assertSee('No report link')
        ->assertDontSee('Open report');
    cmNoJavaScriptErrors($page);
});

it('draws the empty ads table with No ads yet', function (): void {
    PlacardArea::$on['rows'] = fn (): RowPage => new RowPage([], 0);

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.body.innerText.includes("No ads yet")');

    $page->assertSee('No ads yet.');
    cmNoJavaScriptErrors($page);
});

it('draws the table failure line with Try again', function (): void {
    PlacardArea::$on['rows'] = function (): never {
        throw new RuntimeException('rows broken');
    };

    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.body.innerText.includes("couldn’t be shown")');

    $page->assertSee('This part of Placard couldn’t be shown this time.')
        ->assertSee('Try again');
    cmNoJavaScriptErrors($page);
});
