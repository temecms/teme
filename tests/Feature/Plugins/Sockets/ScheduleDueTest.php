<?php

declare(strict_types=1);

use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\Models\PluginRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * When a schedule is due, and how a test fires it (docs/plans/plugin-sockets-2.md, C3):
 * at the first pass after enable, then each time its interval has passed
 * since the last run was queued, catching up once, never several times.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['email' => 'admin@example.com'])->assignRole('administrator');

    PluginFixtures::use('jobs', 'workbench');
    PluginFixtures::installed(['tests/jobs' => 'jobs', 'tests/workbench' => 'workbench']);

    $this->artisan('teme:plugins:enable', ['name' => 'tests/jobs', '--agree' => true, '--as' => 'admin@example.com'])->assertSuccessful();
    Queue::fake();

    $this->queued = fn (): int => Queue::pushed(RunPluginJob::class)->count();
});

afterEach(fn () => PluginFixtures::forget());

it('queues at the first pass, not again the same minute, and again once the interval has passed', function (): void {
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();
    expect(($this->queued)())->toBe(1);

    $this->artisan('teme:plugins:run-due-schedules')->expectsOutputToContain('No plugin schedules were due.');
    expect(($this->queued)())->toBe(1);

    $this->travel(59)->minutes();
    $this->artisan('teme:plugins:run-due-schedules');
    expect(($this->queued)())->toBe(1);

    $this->travel(1)->minutes();
    $this->artisan('teme:plugins:run-due-schedules');
    expect(($this->queued)())->toBe(2);
});

it('catches up two missed intervals once, never twice', function (): void {
    $this->artisan('teme:plugins:run-due-schedules');
    $this->travel(3)->hours();

    $this->artisan('teme:plugins:run-due-schedules');
    $this->artisan('teme:plugins:run-due-schedules');

    expect(($this->queued)())->toBe(2);
});

it('limits a pass to one plugin with --plugin', function (): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/workbench', '--agree' => true, '--as' => 'admin@example.com'])->assertSuccessful();

    $this->artisan('teme:plugins:run-due-schedules', ['--plugin' => 'workbench'])->assertSuccessful();

    expect(Queue::pushed(RunPluginJob::class)->map(fn (RunPluginJob $job): string => $job->pluginCode)->all())->toBe(['workbench']);

    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    expect(Queue::pushed(RunPluginJob::class)->map(fn (RunPluginJob $job): string => $job->pluginCode)->sort()->values()->all())->toBe(['jobs', 'workbench']);
});

it('says when each schedule is next due, as nextDue() does', function (): void {
    $due = app(DueSchedules::class);
    $record = fn (): PluginRecord => PluginRecord::query()->where('name', 'tests/jobs')->sole();

    expect($due->nextDue($record(), 'write-line')?->lessThanOrEqualTo(CarbonImmutable::now()))->toBeTrue()
        ->and($due->nextDue($record(), 'quiet-write'))->toBeNull();

    $this->artisan('teme:plugins:run-due-schedules');
    $next = $due->nextDue($record(), 'write-line');

    expect($next?->toIso8601String())->toBe(CarbonImmutable::now()->addHour()->toIso8601String());

    $this->artisan('teme:plugins:schedules')
        ->expectsTable(
            ['Plugin', 'Job', 'Every', 'Last queued', 'Next due', 'Why it can’t run'],
            [['tests/jobs', 'write-line', 'hour', $record()->schedule_runs['write-line'], $next?->toIso8601String(), '']],
        )
        ->assertSuccessful();
});

it('lists a plugin whose schedules can’t run with the reason', function (): void {
    PluginRecord::query()->where('name', 'tests/jobs')->update(['consented_by' => null]);

    $this->artisan('teme:plugins:schedules')
        ->expectsOutputToContain('Its schedules don’t run: it was enabled from the command line without naming an administrator.')
        ->assertSuccessful();

    // No time is promised for a run that won't happen.
    $this->artisan('teme:plugins:schedules')
        ->expectsTable(
            ['Plugin', 'Job', 'Every', 'Last queued', 'Next due', 'Why it can’t run'],
            [['tests/jobs', 'write-line', 'hour', 'never', '', 'Its schedules don’t run: it was enabled from the command line without naming an administrator. Disable it and enable it again from Site › Plugins, or with --as.']],
        )
        ->assertSuccessful();

    $this->artisan('teme:plugins:run-due-schedules');
    expect(($this->queued)())->toBe(0);
});
