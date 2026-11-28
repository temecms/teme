<?php

/**
 * Custom row actions on a plugin area table: queue as the clicker for that row.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\RunPluginAction;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    Queue::fake();
    $this->editor = User::factory()->create()->assignRole('editor');

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

it('offers custom row actions on the ads table frame', function (): void {
    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.customActions', [
                ['id' => 'check-code', 'label' => 'Check the ad code', 'confirm' => null, 'icon' => 'code'],
                ['id' => 'reset-stats', 'label' => 'Reset statistics…', 'confirm' => 'Its impressions and clicks go back to zero for every period. This can’t be undone.', 'icon' => 'refresh-cw'],
            ])
            ->where('blocks.0.rowActions', ['edit', 'duplicate', 'check-code', 'reset-stats', 'enable', 'disable', 'delete']));
});

it('queues a custom row action as the clicker for that row, with the row toast', function (): void {
    $this->actingAs($this->editor)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'check-code']), [
            'record' => $this->id,
            'name' => 'Summer sale',
        ])
        ->assertRedirect(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Check queued for “Summer sale”. Its status updates when Placard has finished.',
        ]);

    $job = TrackedJob::query()->sole();

    expect($job->started_by)->toBe($this->editor->id)
        ->and($job->payload)->toBe(['action' => 'check-code', 'record' => $this->id])
        ->and($job->unique_key)->toBe(RunPluginAction::uniqueKey('check-code', $this->id));
});

it('queues a confirming row action with its figures toast', function (): void {
    $this->actingAs($this->editor)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'reset-stats']), [
            'record' => $this->id,
            'name' => 'Summer sale',
        ])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Reset queued for “Summer sale”. Its figures show zero once Placard has finished.',
        ]);
});

it('refuses, queuing nothing, a custom row action for a person without the area’s permission', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'check-code']), [
            'record' => $this->id,
            'name' => 'Summer sale',
        ])
        ->assertInertiaFlash('toast.type', 'error');

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
