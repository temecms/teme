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
