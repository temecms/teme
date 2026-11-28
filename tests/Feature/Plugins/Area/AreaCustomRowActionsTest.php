<?php

/**
 * Custom row actions on a plugin area table: queue as the clicker for that row.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\DeclaredArea;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RunPluginActionInput;
use App\Domain\Plugins\Operations\RunPluginAction;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginAreaScreen;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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

it('offers custom row actions on the ads table frame with the plugin’s own words', function (): void {
    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.customActions', [
                [
                    'id' => 'check-code',
                    'label' => 'Check the ad code',
                    'confirm' => null,
                    'queued' => 'Check queued',
                    'icon' => 'code',
                ],
                [
                    'id' => 'reset-stats',
                    'label' => 'Reset statistics…',
                    'confirm' => [
                        'title' => 'Reset the statistics for “{name}”?',
                        'text' => 'Its impressions and clicks go back to zero for every period. This can’t be undone.',
                        'button' => 'Reset statistics',
                        'cancel' => 'Keep them',
                    ],
                    'queued' => 'Reset queued',
                    'icon' => 'refresh-cw',
                ],
            ])
            ->where('blocks.0.columns.3.empty', 'No report link')
            ->where('blocks.0.rowActions', ['edit', 'duplicate', 'check-code', 'reset-stats', 'enable', 'disable', 'delete']));
});

it('hands the figures block its own empty line', function (): void {
    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.empty', 'Figures appear the day after an ad first shows.'));
});

it('defaults empty:true figures and a link without empty text', function (): void {
    $area = PluginFixtures::manifest('placard')['contributes']['area'];
    $area['pages'][0]['blocks'][0]['empty'] = true;
    unset($area['pages'][1]['blocks'][0]['columns'][3]['empty']);

    expect(app(ContributionsValidator::class)->errors(
        [...PluginFixtures::manifest('placard')['contributes'], 'area' => $area],
        '1.0.0',
        ['jobs'],
    ))->toBe([]);

    $plugin = new AreaPlugin('tests/placard', 'placard', 'Placard', DeclaredArea::from($area), null);
    $frame = app(PluginAreaScreen::class)->frame($plugin, 'overview');
    $ads = app(PluginAreaScreen::class)->frame($plugin, 'ads');

    expect($frame['blocks'][0]['empty'] ?? null)->toBe('Figures appear once there is something to count.')
        ->and($ads['blocks'][0]['columns'][3]['empty'] ?? null)->toBe('None');
});

it('queues a custom row action as the clicker for that row, with the plugin’s toast', function (): void {
    $this->actingAs($this->editor)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'check-code']), [
            'record' => $this->id,
            'kind' => 'ad',
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

it('queues a confirming row action with its own figures toast', function (): void {
    $this->actingAs($this->editor)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'reset-stats']), [
            'record' => $this->id,
            'kind' => 'ad',
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
            'kind' => 'ad',
        ])
        ->assertInertiaFlash('toast.type', 'error');

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('leaves row-only actions off the settings group and refuses the wrong record shape, queuing nothing', function (): void {
    expect(app(PluginActions::class)->forGroup('plugin.placard'))
        ->toBe([
            ['id' => 'prune-now', 'label' => 'Prune now', 'description' => 'Removes statistics older than a year.'],
        ]);

    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput(
        'placard',
        'check-code',
        (string) $this->editor->id,
        'plugin.placard.manage',
    )))->toThrow(ValidationException::class);

    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput(
        'placard',
        'prune-now',
        (string) $this->editor->id,
        'plugin.placard.manage',
        $this->id,
    )))->toThrow(ValidationException::class);

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a record id longer than 120 characters, queuing nothing', function (): void {
    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput(
        'placard',
        'check-code',
        (string) $this->editor->id,
        'plugin.placard.manage',
        str_repeat('x', 121),
        'ad',
    )))->toThrow(ValidationException::class);

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps the record id’s case in the unique key and hashes a long one', function (): void {
    expect(RunPluginAction::uniqueKey('check-code', 'AbC'))->toBe('action:check-code:AbC')
        ->and(strlen(RunPluginAction::uniqueKey('check-code', str_repeat('x', 80))))->toBeLessThanOrEqual(64)
        ->and(RunPluginAction::uniqueKey('check-code', str_repeat('x', 80)))->not->toContain(str_repeat('x', 20));
});

it('refuses, queuing nothing, a row action listed as unavailable on that row', function (): void {
    DB::table('placard_ads')->where('id', $this->id)->update(['status' => 'paused', 'impressions' => 0]);

    $this->actingAs($this->editor)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'check-code']), [
            'record' => $this->id,
            'kind' => 'ad',
        ])
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'That action isn’t available for this row.',
        ]);

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    // Without a posted kind, core still finds the table that offers the action.
    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput(
        'placard',
        'check-code',
        (string) $this->editor->id,
        'plugin.placard.manage',
        $this->id,
    )))->toThrow(ValidationException::class);

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues the same row action on another row that still offers it', function (): void {
    DB::table('placard_ads')->where('id', $this->id)->update(['status' => 'paused', 'impressions' => 0]);

    $other = DB::table('placard_ads')->insertGetId([
        'name' => 'Winter sale',
        'status' => 'active',
        'impressions' => 12,
        'data' => json_encode(['location' => 'between_items']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->editor)
        ->from(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'check-code']), [
            'record' => (string) $other,
            'kind' => 'ad',
        ])
        ->assertInertiaFlash('toast.type', 'success');

    expect(TrackedJob::query()->count())->toBe(1)
        ->and(TrackedJob::query()->sole()->payload)->toBe(['action' => 'check-code', 'record' => (string) $other]);
});

it('lists the queued action on the row while the job waits, and clears it when finished', function (): void {
    $this->actingAs($this->editor)
        ->post(route('plugins.area.action', ['code' => 'placard', 'action' => 'check-code']), [
            'record' => $this->id,
            'kind' => 'ad',
        ])
        ->assertInertiaFlash('toast.type', 'success');

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('block_ads', fn (Assert $reload) => $reload
                ->where('block_ads.rows.0.id', $this->id)
                ->where('block_ads.rows.0.queued', ['check-code'])));

    TrackedJob::query()->sole()->update([
        'state' => JobState::Finished,
        'ended_at' => now(),
    ]);

    $this->actingAs($this->editor)
        ->get(route('plugins.area.show', ['code' => 'placard', 'page' => 'ads']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('block_ads', fn (Assert $reload) => $reload
                ->where('block_ads.rows.0.queued', [])));
});
