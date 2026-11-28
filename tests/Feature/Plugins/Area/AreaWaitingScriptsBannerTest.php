<?php

/**
 * The waiting-script banner on a plugin area list page.
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
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\Scripts\ScriptRequests;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
    $this->editor = User::factory()->create()->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    DB::table('placard_ads')->insert([
        'name' => 'Summer sale',
        'status' => 'active',
        'impressions' => 10,
        'data' => json_encode(['location' => 'between_items']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('sends no waiting scripts when none wait', function (): void {
    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page->where('waitingScripts', 0));
});

it('counts one waiting script for the area banner', function (): void {
    ScriptRequests::fromMarkup('placard', 'Ad: Ad code', '<script>adsbygoogle.push({})</script>', (string) $this->editor->id);

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('waitingScripts', 1)
            ->where('waitingNoun', 'ad')
            ->where('waitingNouns', 'ads'));
});

it('counts several waiting scripts for the area banner', function (): void {
    ScriptRequests::fromMarkup('placard', 'Ad: One', '<script>one()</script>', (string) $this->editor->id);
    ScriptRequests::fromMarkup('placard', 'Ad: Two', '<script>two()</script>', (string) $this->editor->id);

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page->where('waitingScripts', 2));
});
