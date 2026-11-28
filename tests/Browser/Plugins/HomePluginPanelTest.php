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
 * A plugin's panel on Home (docs/guides/developers/plugins/home-panels.md),
 * as a person who holds the plugin area's permission sees it outside
 * Customise: under From plugins, with its figures.
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

    DB::table('placard_ads')->insert(['name' => 'Sponsor banner', 'status' => 'active', 'impressions' => 1200, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('shows a permitted person the plugin\'s panel under From plugins on Home, with its figures', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-slot="page-header"]');

    $page->assertSee('From plugins')
        ->assertSee('Ads')
        ->assertSee('Impressions, last 30 days')
        ->assertDontSee('Customising Home');
});
