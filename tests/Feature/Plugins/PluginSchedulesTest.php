<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);

    PluginFixtures::use('jobs');
    PluginFixtures::installed(['tests/jobs' => 'jobs']);

    $this->enable = function (): void {
        $plugin = app(PluginRegistry::class)->find('tests/jobs');
        $manifest = $plugin?->manifest;

        app(EnablePlugin::class)->execute(new EnablePluginInput(
            'tests/jobs',
            $manifest?->version() ?? '1.0.0',
            $manifest?->capabilities() ?? [],
            $manifest?->hosts() ?? [],
            $manifest?->hooks() ?? [],
            null,
        ));
    };
});

afterEach(fn () => PluginFixtures::forget());

it('queues a due schedule once per interval and records schedule_runs', function (): void {
    ($this->enable)();
    Queue::fake();
    $this->travelTo('2026-11-15 12:00:00');

    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    $record = PluginRecord::query()->where('name', 'tests/jobs')->sole();

    expect(TrackedJob::query()->where('owner', 'jobs')->where('run_id', 'write-line')->count())->toBe(1)
        ->and($record->fresh()->schedule_runs['write-line'] ?? null)->toBe(now()->toIso8601String());

    Queue::assertPushed(RunPluginJob::class);

    $this->travelTo('2026-11-15 12:30:00');
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();
    expect(TrackedJob::query()->where('owner', 'jobs')->where('run_id', 'write-line')->count())->toBe(1);

    $this->travelTo('2026-11-15 13:00:00');
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();
    expect(TrackedJob::query()->where('owner', 'jobs')->where('run_id', 'write-line')->count())->toBe(2)
        ->and($record->fresh()->schedule_runs['write-line'] ?? null)->toBe(now()->toIso8601String());
});

it('queues nothing for a disabled plugin, and resumes when enabled again', function (): void {
    ($this->enable)();
    Queue::fake();
    $this->travelTo('2026-11-15 12:00:00');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null));

    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();
    expect(TrackedJob::query()->count())->toBe(0);

    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/jobs',
        '1.0.0',
        ['jobs', 'http'],
        ['api.example.com'],
        [],
        null,
    ));
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    expect(TrackedJob::query()->where('owner', 'jobs')->count())->toBe(1);
});

it('queues nothing when jobs isn’t granted', function (): void {
    ($this->enable)();
    Queue::fake();
    $record = PluginRecord::query()->where('name', 'tests/jobs')->sole();
    $record->consent = ['capabilities' => ['http'], 'hosts' => ['api.example.com'], 'hooks' => []];
    $record->save();

    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    expect(TrackedJob::query()->count())->toBe(0)
        ->and(app(DueSchedules::class)->run())->toBe(0);
});

it('is on the schedule every minute, never overlapping, on one server', function (): void {
    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())->first(
        fn (Event $event): bool => str_contains((string) $event->command, 'teme:plugins:run-due-schedules'),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
