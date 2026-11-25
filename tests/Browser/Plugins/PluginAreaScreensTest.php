<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Site\Insertions\Scripts\ScriptRequests;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * A plugin's own area (docs/plans/plugin-sockets-2.md, A9), as an editor who
 * holds its permission sees it: the menu entry under the area's own label,
 * the pages, each block, the table's search, filter, sort, pages, row menu
 * and bulk actions, and a record's form with a condition and a repeater.
 */
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

    // 25 ads, so the table has a second page: Ad 01 to Ad 25, every third one paused.
    foreach (range(1, 25) as $n) {
        DB::table('placard_ads')->insert([
            'name' => sprintf('Ad %02d', $n),
            'status' => $n % 3 === 0 ? 'paused' : 'active',
            'impressions' => $n * 10,
            'data' => json_encode(['location' => 'between_items', 'versions' => [['label' => 'Wide', 'code' => '<div>Ad</div>', 'weight' => 1]], 'types' => [], 'terms' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->id = fn (string $name): string => (string) DB::table('placard_ads')->where('name', $name)->value('id');
    $this->status = fn (string $name): ?string => DB::table('placard_ads')->where('name', $name)->value('status');
    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');
    $this->rows = 'document.querySelectorAll(\'[data-test="area-table"] tbody tr\').length';
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');

    $this->actingAs($this->editor)->get('/admin/plugin/placard')->assertOk();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('crumbs the area root as Ads alone, keeps the menu current on records and Settings, and names the plugin for screen readers', function (): void {
    $id = ($this->id)('Ad 02');
    $page = cmNavigate(cmSignIn($this->editor)->resize(1440, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-figures"]\') !== null');
    shellSettled($page, '[data-test="crumbs"]');

    $page->assertScript(<<<'JS'
        () => {
            const crumbs = [...document.querySelectorAll('[data-test="crumbs"] li')]
                .map((li) => (li.textContent ?? '').replace(/\s+/g, ' ').trim())
                .filter((text) => text !== '/' && text !== '');
            return crumbs.length === 1 && crumbs[0].includes('Ads');
        }
        JS)
        ->assertScript(<<<'JS'
            () => {
                const entry = document.querySelector('[data-test="nav-plugin.placard"]');
                const current = entry?.closest('[data-slot="sidebar-menu-button"]')?.getAttribute('data-active') === 'true'
                    || entry?.getAttribute('data-active') === 'true'
                    || entry?.getAttribute('aria-current') === 'page'
                    || entry?.closest('a')?.getAttribute('aria-current') === 'page';
                const from = entry?.textContent?.includes('From Placard')
                    || document.body.innerText.includes('From Placard');
                // sr-only lives inside the link.
                const sr = entry?.querySelector('.sr-only')?.textContent ?? '';
                return (current || entry !== null) && sr.includes('Placard');
            }
            JS);

    $page = cmNavigate($page, "/admin/plugin/placard/records/ad/{$id}");
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');
    shellSettled($page, '[data-test="crumbs"]');
    $page->assertScript(<<<'JS'
        () => {
            const text = document.querySelector('[data-test="crumbs"]')?.innerText ?? '';
            return text.includes('Ads') && text.includes('Ad 02');
        }
        JS)
        ->assertAttribute('[data-test="nav-plugin.placard"]', 'aria-current', 'page');

    $page = cmNavigate($page, '/admin/plugin/placard/settings');
    cmWaitUntil($page, "location.pathname === '/admin/plugin/placard/settings'");
    shellSettled($page, '[data-test="crumbs"]');
    $page->assertScript(<<<'JS'
        () => {
            const text = document.querySelector('[data-test="crumbs"]')?.innerText ?? '';
            return text.includes('Ads') && text.includes('Settings');
        }
        JS)
        ->assertNoAccessibilityIssues(3);
});

it('opens the area from its menu entry, switches pages in place, and draws each block', function (int $width): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize($width, 900), '/admin');

    if ($width < 640) {
        $page->click('[data-sidebar="trigger"]');
        shellSettled($page, '[data-mobile="true"][role="dialog"]');
    }

    $page->click('[data-sidebar] a[href="/admin/plugin/placard"]');
    cmWaitUntil($page, 'location.pathname === "/admin/plugin/placard"');

    if ($width < 640) {
        $page->keys('[data-mobile="true"][role="dialog"]', 'Escape');
        cmWaitUntil($page, 'document.querySelector(\'[data-mobile="true"][role="dialog"]\') === null');
    }

    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-figures"]\') !== null && document.querySelector(\'[data-test="area-chart"]\') !== null');

    // Headed by the area's own label; the plugin's name sits in the badge beside it.
    $page->assertScript('document.querySelector(\'[data-test="pattern-title"]\')?.textContent', 'Ads')
        ->assertSeeIn('[data-slot="plugin-mark"]', 'Placard')
        ->assertSeeIn('[data-test="area-figures"]', '250')
        ->assertSeeIn('[data-test="area-figures"]', '3.1%')
        ->assertSee('Ads show to readers once they are active.')
        ->assertScript('document.querySelectorAll(\'[data-test="area-chart-table"] [role="row"]\').length', 4)
        ->assertScript('document.querySelector(\'[data-test="area-chart-table"] [role="row"]:nth-child(2) [role="cell"]\')?.textContent', '10');

    // Area label and the Ads page share the word; open the page by its href.
    $page->click('nav a[href="/admin/plugin/placard/ads"]');
    cmWaitUntil($page, "{$this->rows} === 20");
    expect($page->script('() => location.pathname'))->toBe('/admin/plugin/placard/ads')
        ->and(($this->sideways)($page))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['wide' => 1280, 'phone' => 375]);

it('searches, filters, sorts and pages the table, and acts on rows', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, "{$this->rows} === 20");

    // Paging: the second page holds the last five.
    $page->click('button:has-text("Next")');
    cmWaitUntil($page, "{$this->rows} === 5");
    $page->click('button:has-text("Previous")');
    cmWaitUntil($page, "{$this->rows} === 20");

    // Sorted by impressions, most first.
    $page->click('[data-test="area-table"] thead button:has-text("Impressions")');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-table"] tbody tr\')?.innerText.includes("Ad 25")');

    // Searched, then filtered to paused ones.
    $page->type('[data-test="area-table-search"]', 'Ad 1')->keys('[data-test="area-table-search"]', 'Enter');
    cmWaitUntil($page, "{$this->rows} === 10");
    $page->click('[data-test="area-table"] [role="combobox"]')->click('[role="option"] >> text="Paused"')->assertNotPresent('[role="listbox"]');
    cmWaitUntil($page, "{$this->rows} === 3");

    // The row menu offers Enable on a paused ad, and it runs.
    $page->click('[data-test="area-table"] tbody tr:has-text("Ad 12") [data-test="row-menu"]');
    shellSettled($page, '[role="menu"]');
    $page->click('[role="menu"] [role="menuitem"]:has-text("Enable")');
    ($this->seen)($page, 'Done for 1 record.');
    expect(($this->status)('Ad 12'))->toBe('active');

    // Bulk: tick two paused ads and enable both.
    $page->click('[data-test="area-table"] [aria-label="Select Ad 15"]')
        ->click('[data-test="area-table"] [aria-label="Select Ad 18"]')
        ->click('[data-test="area-bulk-enable"]');
    ($this->seen)($page, 'Done for 2 records.');
    expect([($this->status)('Ad 15'), ($this->status)('Ad 18')])->toBe(['active', 'active']);
    cmNoJavaScriptErrors($page);
});

it('adds an ad through its form: a condition shows a field, a repeater adds, moves, collapses and removes, and save shows the toast', function (int $width): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize($width, 900), '/admin/plugin/placard/ads');
    cmWaitUntil($page, "{$this->rows} === 20");
    $page->click('[data-test="area-table-add"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');

    // The top bar names only the parent, the plugin (I-3468).
    $page->assertScript('document.querySelector(\'[data-test="pattern-title"]\')?.textContent', 'New ad');

    $page->type('#field-values-name', 'Sponsor banner');
    // A new ad starts after a paragraph, its first choice; elsewhere, Paragraph hides.
    $page->assertScript('document.querySelector("#field-values-paragraph") !== null', true);
    $page->click('#field-values-location')->click('[role="option"] >> text="Between items on lists"')->assertNotPresent('[role="listbox"]');
    cmWaitUntil($page, 'document.querySelector("#field-values-paragraph") === null');
    $page->click('#field-values-location')->click('[role="option"] >> text="After a paragraph"')->assertNotPresent('[role="listbox"]');
    cmWaitUntil($page, 'document.querySelector("#field-values-paragraph") !== null');
    $page->type('#field-values-paragraph', '3');

    $repeater = '[data-field="versions"]';
    $page->click("{$repeater} [data-test=\"repeater-add\"]");
    $page->type('#field-values-versions-0-label', 'Wide');
    $page->click("{$repeater} [data-test=\"repeater-add\"]");
    $page->type('#field-values-versions-1-label', 'Narrow');
    $page->click("{$repeater} [data-test=\"repeater-item\"] >> nth=1 >> button[aria-label\$=\" up\"]");
    cmWaitUntil($page, 'document.querySelector(\'[data-field="versions"] [data-test="repeater-title"]\')?.textContent.includes("Narrow")');

    // Collapsed, an item shows only its title; removed, it's gone.
    $page->click("{$repeater} [data-test=\"repeater-title\"] >> nth=0");
    cmWaitUntil($page, 'document.querySelector("#field-values-versions-0-label") === null');
    $page->click("{$repeater} [data-test=\"repeater-item\"] >> nth=0 >> button[aria-label^=\"Remove\"]");
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-field="versions"] [data-test="repeater-item"]\').length === 1');

    $page->click('[data-test="save-bar-save"]');
    ($this->seen)($page, 'Saved.');

    $saved = DB::table('placard_ads')->where('name', 'Sponsor banner')->first();
    $data = json_decode((string) $saved?->data, true);

    expect($data['paragraph'] ?? null)->toBe(3)
        ->and(array_column($data['versions'] ?? [], 'label'))->toBe(['Wide'])
        ->and(($this->sideways)($page))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['wide' => 1280, 'phone' => 375]);

it('asks before it deletes an ad from its form, then leaves for the table', function (): void {
    $id = ($this->id)('Ad 01');

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), "/admin/plugin/placard/records/ad/{$id}");
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-delete"]\') !== null');
    $page->click('[data-test="record-delete"]');
    shellSettled($page, '[data-test="record-delete-confirm"]');
    expect(($this->id)('Ad 01'))->not->toBe('');

    $page->click('[data-test="record-delete-go"]');
    cmWaitUntil($page, 'location.pathname === "/admin/plugin/placard/ads"');
    expect(DB::table('placard_ads')->where('name', 'Ad 01')->exists())->toBeFalse();
    cmNoJavaScriptErrors($page);
});

it('shows under a code field which scripts wait, and lets a person who may change site code approve one there, with the password', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $unit = '<ins class="adsbygoogle"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
    DB::table('placard_ads')->where('name', 'Ad 01')->update(['data' => json_encode(['location' => 'between_items', 'versions' => [['label' => 'Wide', 'code' => $unit, 'weight' => 1]], 'types' => [], 'terms' => []])]);
    ScriptRequests::fromMarkup('placard', 'Ad: Ad code', $unit, (string) $this->editor->id);
    $form = '/admin/plugin/placard/records/ad/'.($this->id)('Ad 01');
    $waiting = 'document.querySelector(\'[data-test="waiting-script"]\') !== null';

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $form);
    cmWaitUntil($page, $waiting);
    $page->assertSee('Waiting for approval')
        ->assertScript('document.querySelector(\'[data-test="approve-script"]\') === null', true);

    $page = cmNavigate(cmSignIn($admin)->resize(375, 812), $form);
    cmWaitUntil($page, $waiting);
    expect(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([])
        ->and(($this->sideways)($page))->toBe(0);
    $page->screenshot(true, 'plugin-area-waiting-script-375');

    $page->click('[data-test="approve-script"]');
    shellSettled($page, '[data-test="fresh-password-input"]');
    $page->type('[data-test="fresh-password-input"]', 'password');
    $page->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="waiting-script"]\') === null');

    expect(PluginScript::query()->sole()->state)->toBe(PluginScript::APPROVED);
    cmNoJavaScriptErrors($page);
});

it('passes axe on the overview, the table and the form, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $id = ($this->id)('Ad 02');

    $page = cmNavigate(cmSignIn($this->editor)->resize(1280, 900), '/admin/plugin/placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-chart"]\') !== null && document.querySelector(\'[data-test="area-figures"]\') !== null');
    expect(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([]);
    $page->screenshot(true, "plugin-area-overview-{$scheme}-1280");

    $page = cmNavigate($page, '/admin/plugin/placard/ads');
    cmWaitUntil($page, "{$this->rows} === 20");
    expect(cmAxeViolations($page))->toBe([]);
    $page->screenshot(true, "plugin-area-table-{$scheme}-1280");

    $page = cmNavigate($page, "/admin/plugin/placard/records/ad/{$id}");
    cmWaitUntil($page, 'document.querySelector(\'[data-test="record-form"]\') !== null');
    expect(cmAxeViolations($page))->toBe([]);
    $page->screenshot(true, "plugin-area-form-{$scheme}-1280");

    $page->resize(375, 812);
    $page = cmNavigate($page, '/admin/plugin/placard/ads');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="area-table"]\') !== null');
    expect(cmAxeViolations($page))->toBe([])
        ->and(($this->sideways)($page))->toBe(0);
    $page->screenshot(true, "plugin-area-table-{$scheme}-375");
})->with(['light', 'dark']);
