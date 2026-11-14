<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Deployment\Health\PendingMigrations;
use App\Domain\Deployment\Jobs\RunReleaseRebuild;
use App\Domain\Deployment\Operations\Input\QueueReleaseRebuildsInput;
use App\Domain\Deployment\Operations\QueueReleaseRebuilds;
use App\Domain\Deployment\ReleaseRebuilds;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Deployment\DeployRun;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->travelTo(CarbonImmutable::parse('2027-01-26 12:00:00'));
});

it('records a rebuild a release asks for, once, waiting to be queued (I-3113)', function (): void {
    ReleaseRebuilds::ask('search');
    ReleaseRebuilds::ask('search');
    $rows = DB::table('release_rebuilds')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->rebuild)->toBe('search')
        ->and(CarbonImmutable::parse((string) $rows[0]->asked_at)->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($rows[0]->queued_at)->toBeNull();
});

it('refuses a rebuild this code doesn\'t know, so the migration that asks for it fails', function (): void {
    expect(fn () => ReleaseRebuilds::ask('serach'))->toThrow(InvalidArgumentException::class, 'No rebuild is called serach. A release asks only for one of: addresses, search.')
        ->and(DB::table('release_rebuilds')->count())->toBe(0);
});

it('makes the queued job of every rebuild it knows, for the actor given, and none for one it doesn\'t', function (): void {
    $actor = JobActor::operator();
    $rebuilds = app(ReleaseRebuilds::class);

    foreach (ReleaseRebuilds::KEYS as $rebuild) {
        expect($rebuilds->job($rebuild, $actor))->toBeInstanceOf(ShouldQueue::class);
    }

    expect($rebuilds->job('addresses', $actor))->toBeInstanceOf(RecomputeAddresses::class)
        ->and($rebuilds->job('search', $actor))->toBeInstanceOf(ReindexSearchJob::class)
        ->and($rebuilds->job('search', $actor)?->actor)->toBe($actor)
        ->and($rebuilds->job('gone', $actor))->toBeNull();
});

it('queues each waiting rebuild as the operator, after commit, and marks it queued', function (): void {
    Queue::fake();
    ReleaseRebuilds::ask('search');
    ReleaseRebuilds::ask('addresses');

    $queued = app(QueueReleaseRebuilds::class)->execute(new QueueReleaseRebuildsInput);

    expect($queued)->toBe(['addresses', 'search'])
        ->and(DB::table('release_rebuilds')->whereNull('queued_at')->count())->toBe(0);
    Queue::assertPushed(RunReleaseRebuild::class, 2);
    Queue::assertPushed(RunReleaseRebuild::class, fn (RunReleaseRebuild $job): bool => $job->rebuild === 'search'
        && $job->releaseMigration === app(PendingMigrations::class)->newest()
        && $job->actor->isOperator());
});

it('puts a request back when its job can\'t be queued after commit, so the next deploy queues it', function (): void {
    ReleaseRebuilds::ask('search');
    config(['queue.default' => 'teme-unqueueable', 'queue.connections.teme-unqueueable' => ['driver' => 'teme-no-such-driver']]);

    expect(fn () => app(QueueReleaseRebuilds::class)->execute(new QueueReleaseRebuildsInput))->toThrow(Exception::class)
        ->and(DB::table('release_rebuilds')->where('rebuild', 'search')->value('queued_at'))->toBeNull();
});

it('queues a rebuild once, and again only when a later release asks again', function (): void {
    Queue::fake();
    ReleaseRebuilds::ask('search');
    app(QueueReleaseRebuilds::class)->execute(new QueueReleaseRebuildsInput);

    expect(app(QueueReleaseRebuilds::class)->execute(new QueueReleaseRebuildsInput))->toBe([]);

    ReleaseRebuilds::ask('search');

    expect(app(QueueReleaseRebuilds::class)->execute(new QueueReleaseRebuildsInput))->toBe(['search']);
    Queue::assertPushed(RunReleaseRebuild::class, 2);
});

it('writes nothing and queues nothing when the operator isn\'t present', function (): void {
    Queue::fake();
    ReleaseRebuilds::ask('search');
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(QueueReleaseRebuilds::class)->execute(new QueueReleaseRebuildsInput))->toThrow(AuthorizationException::class, "Only the operator queues a release's rebuilds.")
        ->and(DB::table('release_rebuilds')->whereNull('queued_at')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('hands its rebuild to the job that does it, on a worker of its release without the operator\'s elevation (I-392)', function (): void {
    $job = (new RunReleaseRebuild('search', DeployRun::REBUILDS, JobActor::operator()))->withFakeQueueInteractions();
    app()->instance(Elevation::class, new DeniedElevation);
    Queue::fake();

    $job->handle(app(ReleaseRebuilds::class), app(PendingMigrations::class));

    $job->assertNotReleased();
    Queue::assertPushed(ReindexSearchJob::class, fn (ReindexSearchJob $pushed): bool => $pushed->actor->isOperator());
});

it('puts itself back for a minute on a worker that doesn\'t ship its release\'s migration, for up to a day', function (string $rebuild): void {
    Queue::fake();
    $job = (new RunReleaseRebuild($rebuild, '2099_12_31_000000_a_later_release', JobActor::operator()))->withFakeQueueInteractions();

    $job->handle(app(ReleaseRebuilds::class), app(PendingMigrations::class));

    $job->assertReleased(60);
    Queue::assertNothingPushed();
    expect(CarbonImmutable::instance($job->retryUntil())->equalTo(CarbonImmutable::now()->addDay()))->toBeTrue()
        ->and($job->maxExceptions)->toBe(3);
})->with([
    'a rebuild this worker knows, whose meaning the later release may have changed' => ['search'],
    'a rebuild this worker doesn\'t know' => ['from-a-later-release'],
]);

it('fails when its release\'s worker doesn\'t know its rebuild, which is a bug', function (): void {
    Queue::fake();
    $job = new RunReleaseRebuild('gone', DeployRun::REBUILDS, JobActor::operator());

    expect(fn () => $job->handle(app(ReleaseRebuilds::class), app(PendingMigrations::class)))->toThrow(LogicException::class, 'No rebuild is called gone in the release that queued it.');
    Queue::assertNothingPushed();
});
