<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Deployment\Health\RecordQueueHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Deployment\QuietJob;
use Tests\Fixtures\Deployment\UnreachableDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

it('keeps one row a name, however often it beats (I-3112)', function (): void {
    $heartbeats = app(Heartbeats::class);

    foreach ([0, 1, 1] as $minutes) {
        $this->travel($minutes)->minutes();
        $heartbeats->beat(Heartbeats::SCHEDULER);
    }

    expect(DB::table('heartbeats')->count())->toBe(1)
        ->and($heartbeats->lastBeat(Heartbeats::SCHEDULER)?->equalTo(CarbonImmutable::parse('2026-10-20 12:02:00')))->toBeTrue();
});

it('leaves a row beaten under ten seconds ago as it is, so a busy queue rewrites it at most that often', function (): void {
    $heartbeats = app(Heartbeats::class);
    $heartbeats->beat('queue:default');
    $this->travel(9)->seconds();
    $heartbeats->beat('queue:default');

    expect($heartbeats->lastBeat('queue:default')?->equalTo(CarbonImmutable::parse('2026-10-20 12:00:00')))->toBeTrue();

    $this->travel(2)->seconds();
    $heartbeats->beat('queue:default');

    expect($heartbeats->lastBeat('queue:default')?->equalTo(CarbonImmutable::parse('2026-10-20 12:00:11')))->toBeTrue();
});

it('drops a beat it can\'t write, and never throws, as a sink\'s line is', function (): void {
    expect(fn () => UnreachableDatabase::during(fn () => app(Heartbeats::class)->beat(Heartbeats::SCHEDULER)))
        ->not->toThrow(Throwable::class);
});

it('beats for the queue a worker took a job from, when the job is done and when it has failed', function (): void {
    Queue::connection('database')->push(new QuietJob, '', 'long');
    $job = Queue::connection('database')->pop('long');

    app(RecordQueueHeartbeat::class)->handle(new JobProcessed('database', $job));

    expect(array_keys(app(Heartbeats::class)->queues()))->toBe(['long']);

    $this->travel(1)->minutes();
    app(RecordQueueHeartbeat::class)->handle(new JobExceptionOccurred('database', $job, new RuntimeException('Broken.')));

    expect(app(Heartbeats::class)->lastBeat(Heartbeats::QUEUE_PREFIX.'long')?->equalTo(CarbonImmutable::parse('2026-10-20 12:01:00')))->toBeTrue();
});

it('never beats for a job run without a worker: sync, deferred or in the background', function (): void {
    $job = new SyncJob(app(), (string) json_encode(['job' => QuietJob::class]), 'sync', 'default');

    app(RecordQueueHeartbeat::class)->handle(new JobProcessed('sync', $job));

    expect(DB::table('heartbeats')->count())->toBe(0);
});

it('beats for the scheduler every minute, first in the schedule', function (): void {
    $first = app(Schedule::class)->events()[0];

    expect($first->description)->toBe('teme:heartbeat')
        ->and($first->expression)->toBe('* * * * *');

    $first->run(app());

    expect(app(Heartbeats::class)->lastBeat(Heartbeats::SCHEDULER)?->equalTo(CarbonImmutable::parse('2026-10-20 12:00:00')))->toBeTrue();
});
