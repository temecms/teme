<?php

/**
 * A record's page header: status pill, meta line, All … and Delete in the header.
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
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\RecordHeader;
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
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    DB::table('placard_ads')->insert([
        'name' => 'Summer sale',
        'status' => 'active',
        'impressions' => 40,
        'data' => json_encode(['location' => 'between_items']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->id = (string) DB::table('placard_ads')->where('name', 'Summer sale')->value('id');
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('sends status and meta for a record’s header, with All and Delete in the actions', function (): void {
    PlacardArea::$on['header'] = fn (string $record, string $id, AreaContext $context): RecordHeader => new RecordHeader(
        'Active',
        'ok',
        ['40 impressions in the last 30 days', 'last changed by Eve Editor'],
    );

    $this->actingAs($this->editor)
        ->get(route('plugins.area.edit', ['code' => 'placard', 'record' => 'ad', 'id' => $this->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('header.status', 'Active')
            ->where('header.tone', 'ok')
            ->where('header.meta', ['40 impressions in the last 30 days', 'last changed by Eve Editor'])
            ->where('deletable', true));
});

it('sends status only when the plugin gives no meta', function (): void {
    PlacardArea::$on['header'] = fn (): RecordHeader => new RecordHeader('Paused', 'neutral', []);

    $this->actingAs($this->editor)
        ->get(route('plugins.area.edit', ['code' => 'placard', 'record' => 'ad', 'id' => $this->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('header.status', 'Paused')
            ->where('header.meta', []));
});

it('sends a null header when the plugin supplies none, with no empty gap', function (): void {
    PlacardArea::$on['header'] = fn (): ?RecordHeader => null;

    $this->actingAs($this->editor)
        ->get(route('plugins.area.edit', ['code' => 'placard', 'record' => 'ad', 'id' => $this->id]))
        ->assertInertia(fn (Assert $page) => $page->where('header', null));
});

it('sends a null header when reading it fails, never an error page', function (): void {
    PlacardArea::$on['header'] = function (): never {
        throw new RuntimeException('header broken');
    };

    $this->actingAs($this->editor)
        ->get(route('plugins.area.edit', ['code' => 'placard', 'record' => 'ad', 'id' => $this->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('header', null));
});
