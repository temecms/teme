<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\ManifestValidator;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('scheduler');
    PluginFixtures::installed(['tests/scheduler' => 'scheduler']);
    $manifest = PluginFixtures::manifest('scheduler');
    app(SettingsRegistry::class)->group(DeclaredSettings::group('scheduler', 'Scheduler', 'Publishes a queue.', '1.0.0', $manifest['contributes']['settings']), 'tests/scheduler');

    $found = app(PluginRegistry::class)->find('tests/scheduler')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/scheduler',
        $found?->version() ?? '1.0.0',
        $found?->capabilities() ?? [],
        $found?->hosts() ?? [],
        $found?->hooks() ?? [],
        (string) $this->admin->id,
    ));

    $this->set = fn (array $values) => app(UpdateSettings::class)
        ->execute(new UpdateSettingsInput('plugin.scheduler', $values, [], [], (int) (SettingsGroupRecord::query()->whereKey('plugin.scheduler')->value('version') ?? 0), (string) $this->admin->id));
    $this->runs = function (string $at): int {
        $this->travelTo($at);
        $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

        return TrackedJob::query()->where('owner', 'scheduler')->where('run_id', 'publish-queue')->count();
    };

    Queue::fake();
});

afterEach(fn () => PluginFixtures::forget());

/**
 * The validator's errors for the scheduler's manifest with this schedule and these settings.
 *
 * @param  array<string, mixed>  $schedule
 * @param  list<array<string, mixed>>  $settings
 *
 * @return list<string>
 */
function ssErrors(array $schedule, array $settings): array
{
    $manifest = PluginFixtures::manifest('scheduler');
    $manifest['schedules'] = [['job' => 'publish-queue', 'every' => 'hour', ...$schedule]];
    $manifest['contributes']['settings'] = $settings;

    return app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('scheduler'), null);
}

it('runs nothing while its switch is off, and starts at the next minute’s check once it is on', function (): void {
    expect(($this->runs)('2026-11-15 12:00:00'))->toBe(0);

    ($this->set)(['active' => true]);

    expect(($this->runs)('2026-11-15 12:01:00'))->toBe(1)
        ->and(($this->runs)('2026-11-15 12:30:00'))->toBe(1)
        ->and(($this->runs)('2026-11-15 13:01:00'))->toBe(2);

    ($this->set)(['active' => false]);

    expect(($this->runs)('2026-11-15 15:00:00'))->toBe(2);
});

it('takes how often it runs from its setting, a change counting at the next minute’s check', function (): void {
    ($this->set)(['active' => true, 'frequency' => 'day']);

    expect(($this->runs)('2026-11-15 12:00:00'))->toBe(1)
        ->and(($this->runs)('2026-11-15 13:00:00'))->toBe(1)
        ->and(($this->runs)('2026-11-16 12:00:00'))->toBe(2);

    ($this->set)(['frequency' => 'hour']);

    expect(($this->runs)('2026-11-16 13:00:00'))->toBe(3);
});

it('accepts a schedule switched and timed by its own settings', function (): void {
    expect(app(ManifestValidator::class)->errors(PluginFixtures::manifest('scheduler'), PluginFixtures::path('scheduler'), null))->toBe([]);
});

it('refuses a switch or a timing setting it can’t use', function (array $schedule, array $settings, string $error): void {
    expect(ssErrors($schedule, $settings))->toContain($error);
})->with([
    'a switch that isn’t declared' => [['enabledBy' => 'missing'], [], 'schedules enabledBy names "missing", which isn’t one of the plugin’s boolean settings.'],
    'a switch that isn’t a boolean' => [['enabledBy' => 'note'], [['key' => 'note', 'type' => 'text', 'label' => 'Note', 'description' => 'A note.']], 'schedules enabledBy names "note", which isn’t one of the plugin’s boolean settings.'],
    'a timing setting that isn’t a select' => [['everyFrom' => 'note'], [['key' => 'note', 'type' => 'text', 'label' => 'Note', 'description' => 'A note.']], 'schedules everyFrom names "note", which isn’t a select setting whose options are all minute, five-minutes, fifteen-minutes, hour, day or week.'],
    'a timing option outside the intervals' => [['everyFrom' => 'often'], [['key' => 'often', 'type' => 'select', 'label' => 'Often', 'description' => 'How often.', 'options' => ['hour' => 'Hourly', 'second' => 'Every second']]], 'schedules everyFrom names "often", which isn’t a select setting whose options are all minute, five-minutes, fifteen-minutes, hour, day or week.'],
]);

it('lists no next-due time for a schedule its setting switches off, and says why', function (): void {
    $this->artisan('teme:plugins:schedules')
        ->expectsTable(
            ['Plugin', 'Job', 'Every', 'Last queued', 'Next due', 'Why it can’t run'],
            [['tests/scheduler', 'publish-queue', 'hour', 'never', '', 'Its setting switches this schedule off.']],
        )
        ->assertSuccessful();
});
