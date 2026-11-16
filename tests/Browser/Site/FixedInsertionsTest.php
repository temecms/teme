<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PagePosition;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();
    // Only what is fixed: nothing that reaches a host outside the test.
    Billboard::$gives = [
        new PageInsertion('billboard.bar', PagePosition::fixed('bottom'), '<p data-test="billboard-bar">A bar along the bottom</p>'),
        new PageInsertion('billboard.rail', PagePosition::fixed('right', 160), '<p data-test="billboard-rail">A rail</p>'),
    ];

    Storage::fake('media');
    Storage::fake('media-originals');
    // Core's design site: its menus are bound, as a real site's are.
    $this->site = dtSite(User::factory()->create()->assignRole('editor'));
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

it('fixes a bar to the bottom that closes and stays closed on the next page in the tab, beside a rail on a wide screen', function (): void {
    $page = visit('/')->resize(1280, 900);

    $page->assertScript("getComputedStyle(document.querySelector('[data-teme-fixed=\"billboard.bar\"]')).position === 'fixed'")
        ->assertScript("Math.round(document.querySelector('[data-teme-fixed=\"billboard.bar\"]').getBoundingClientRect().bottom) === window.innerHeight")
        ->assertScript("getComputedStyle(document.querySelector('[data-teme-fixed=\"billboard.rail\"]')).display !== 'none'")
        ->assertScript('parseFloat(getComputedStyle(document.body).paddingBlockEnd) > 0')
        ->assertNoAccessibilityIssues(3);

    $page->click('[data-teme-fixed="billboard.bar"] [data-teme-fixed-close]')
        ->assertScript("document.querySelector('[data-teme-fixed=\"billboard.bar\"]') === null")
        ->assertScript("getComputedStyle(document.body).paddingBlockEnd === '0px'");

    $page->navigate(dtUrl($this->site, 'longRead'))
        ->assertSee('A year on the harbour')
        ->assertScript("document.querySelector('[data-teme-fixed=\"billboard.bar\"]') === null")
        ->assertScript("document.querySelector('[data-teme-fixed=\"billboard.rail\"]') !== null");
});

it('hides the rail on a phone and keeps the bar', function (): void {
    visit('/')->resize(375, 812)
        ->assertScript("getComputedStyle(document.querySelector('[data-teme-fixed=\"billboard.rail\"]')).display === 'none'")
        ->assertScript("getComputedStyle(document.querySelector('[data-teme-fixed=\"billboard.bar\"]')).display !== 'none'")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoAccessibilityIssues(3);
});
