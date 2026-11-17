<?php

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
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 2).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * A plugin's settings page with sections and conditions (docs/plans/plugin-sockets-2.md,
 * A4, A5): each section a panel in the declared order, a setting shown only
 * while its condition holds, and a repeater setting's items.
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
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('placard');
    $manifest = PluginFixtures::manifest('placard');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();

    $this->stored = fn (): array => json_decode((string) DB::table('settings')->where('group_key', 'plugin.placard')->value('overrides'), true) ?: [];
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');

    $this->actingAs($this->admin)->get('/admin/settings/plugin.placard')->assertOk();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('draws each section as a panel and shows a setting only while its condition holds', function (int $width): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/settings/plugin.placard');
    cmWaitUntil($page, 'document.querySelector("#field-label_text") !== null');

    $page->assertSee('General')
        ->assertSee('Ad network')
        ->assertScript('document.querySelector("#field-publisher_id")?.closest("[hidden]") !== null', true);

    $page->select('#field-provider', 'adsense');
    cmWaitUntil($page, 'document.querySelector("#field-publisher_id")?.closest("[hidden]") === null');
    $page->type('#field-publisher_id', 'pub-1234');

    $page->click('[data-test="settings-save"]');
    cmWaitUntil($page, 'document.body.innerText.includes("Settings saved.")');

    expect(($this->stored)())->toMatchArray(['provider' => 'adsense', 'publisher_id' => 'pub-1234'])
        ->and(($this->sideways)($page))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['wide' => 1280, 'phone' => 375]);

it('adds an item to a repeater setting, moves it and keeps it', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/settings/plugin.placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="repeater-setting"]\') !== null');

    $page->click('[data-test="repeater-setting"] [data-test="repeater-add"]');
    $page->type('#field-values-slots-0-name', 'Top');
    $page->click('[data-test="repeater-setting"] [data-test="repeater-add"]');
    $page->type('#field-values-slots-1-name', 'Bottom');
    $page->click('[data-test="repeater-setting"] [data-test="repeater-item"] >> nth=1 >> button[aria-label$=" up"]');

    $page->click('[data-test="settings-save"]');
    cmWaitUntil($page, 'document.body.innerText.includes("Settings saved.")');

    expect(array_column(($this->stored)()['slots'] ?? [], 'name'))->toBe(['Bottom', 'Top']);
    cmNoJavaScriptErrors($page);
});

it('passes axe with sections, a condition and a repeater, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/settings/plugin.placard');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="repeater-setting"]\') !== null');
    $page->click('[data-test="repeater-setting"] [data-test="repeater-add"]');

    expect(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([]);
    $page->screenshot(true, "plugin-settings-sections-{$scheme}-1280");
})->with(['light', 'dark']);
