<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Status\Checks\QueueWorkerRunning;
use App\Domain\Status\Checks\SchedulerRunning;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Deployment\HealthRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    app()->instance('env', 'production');
    config(['queue.connections.database.retry_after' => 90]);
});

it('shows neither banner outside production, however long both have been silent (I-3112)', function (): void {
    app()->instance('env', 'testing');
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 10:00:00'));

    expect(app(SchedulerRunning::class)->banner())->toBeNull()
        ->and(app(QueueWorkerRunning::class)->banner())->toBeNull();
});

it('shows neither banner, and never fails the admin, while the heartbeats table is missing, as before its migration', function (): void {
    // Rolled back with the test. The failed read leaves the test's transaction
    // unusable, so nothing after it queries.
    Schema::drop('heartbeats');

    expect(app(SchedulerRunning::class)->banner())->toBeNull()
        ->and(app(QueueWorkerRunning::class)->banner())->toBeNull();
});

it('says a production site\'s scheduler has never run when it has no heartbeat, to those who may fix it', function (): void {
    $banner = app(SchedulerRunning::class)->banner();

    expect(app(SchedulerRunning::class)->ability())->toBe('settings.general.manage')
        ->and($banner?->level)->toBe('error')
        ->and($banner?->priority)->toBe(110)
        ->and($banner?->actionUrl)->toBeNull()
        ->and($banner?->message)->toBe("Scheduled posts aren't publishing: the scheduler hasn't run on this site yet, so backups and other timed work wait too. Whoever runs the server should start it.");
});

it('waits until the scheduler has been silent five minutes, then says when it last ran', function (): void {
    HealthRows::beat(Heartbeats::SCHEDULER, CarbonImmutable::parse('2026-10-20 12:00:00'));

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:04:59'));
    expect(app(SchedulerRunning::class)->banner())->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:05:00'));
    expect(app(SchedulerRunning::class)->banner()?->message)->toBe("Scheduled posts aren't publishing: the scheduler last ran 5 minutes ago, so backups and other timed work have stopped too. Whoever runs the server should start it.");

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:12:00'));
    expect(app(SchedulerRunning::class)->banner()?->message)->toContain('the scheduler last ran 12 minutes ago,');
});

it('names the queues whose jobs have waited ten minutes with no worker taking them', function (): void {
    HealthRows::job('long', CarbonImmutable::parse('2026-10-20 11:50:00'));
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:46:00'));
    HealthRows::job('images', CarbonImmutable::parse('2026-10-20 11:55:00'));

    $banner = app(QueueWorkerRunning::class)->banner();

    expect(app(QueueWorkerRunning::class)->ability())->toBe('settings.general.manage')
        ->and($banner?->level)->toBe('error')
        ->and($banner?->priority)->toBe(105)
        ->and($banner?->message)->toBe("Uploaded images' sizes and other background work are waiting: jobs on the default and long queues have waited 14 minutes with no worker taking them. Whoever runs the server should start a worker for each.");
});

it('counts a job a dead worker left reserved, from when its reservation ran out', function (): void {
    // Held at 11:45 for retry_after's 90 seconds: free again, and waiting, from 11:46:30.
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:40:00'), CarbonImmutable::parse('2026-10-20 11:45:00'));

    expect(app(QueueWorkerRunning::class)->banner()?->message)->toBe("Uploaded images' sizes and other background work are waiting: jobs on the default queue have waited 13 minutes with no worker taking them. Whoever runs the server should start a worker for it.");
});

it('stays quiet while a worker holds a job on the queue, however long the others have waited', function (): void {
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:30:00'));
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:30:00'), CarbonImmutable::parse('2026-10-20 11:59:00'));

    expect(app(QueueWorkerRunning::class)->banner())->toBeNull();
});

it('stays quiet for a queue a worker finished a job on in the last ten minutes, and names the others', function (): void {
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:40:00'));
    HealthRows::job('long', CarbonImmutable::parse('2026-10-20 11:40:00'));
    HealthRows::beat(Heartbeats::QUEUE_PREFIX.'default', CarbonImmutable::parse('2026-10-20 11:51:00'));

    expect(app(QueueWorkerRunning::class)->banner()?->message)->toContain('jobs on the long queue have waited 20 minutes');
});

it('ignores jobs delayed into the future and jobs that have waited under ten minutes', function (): void {
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 12:30:00'));
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:50:01'));

    expect(app(QueueWorkerRunning::class)->banner())->toBeNull();
});

it('reads retry_after from the longest database connection, so a long queue\'s running job is a worker at work', function (): void {
    config(['queue.connections.database-long' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'long', 'retry_after' => 360]]);
    HealthRows::job('long', CarbonImmutable::parse('2026-10-20 11:30:00'));
    // Five minutes into a six-minute reservation.
    HealthRows::job('long', CarbonImmutable::parse('2026-10-20 11:30:00'), CarbonImmutable::parse('2026-10-20 11:55:00'));

    expect(app(QueueWorkerRunning::class)->banner())->toBeNull();

    config(['queue.connections.database-long' => null]);

    expect(app(QueueWorkerRunning::class)->banner()?->message)->toContain('jobs on the long queue have waited 30 minutes');
});
