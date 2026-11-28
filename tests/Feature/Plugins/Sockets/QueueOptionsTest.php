<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\QueueOptions;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('jobs');
    PluginFixtures::installed(['tests/jobs' => 'jobs']);

    $manifest = app(PluginRegistry::class)->find('tests/jobs')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/jobs', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], (string) $this->admin->id));

    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();
    $instance = app(LoadedPlugins::class)->running()['tests/jobs'];

    /** @var Jobs $jobs */
    $this->jobs = (new ReflectionClass($instance))->getProperty('jobs')->getValue($instance);
    Auth::login($this->admin);
    Queue::fake();
});

afterEach(fn () => PluginFixtures::forget());

it('queues the run after the delay asked for', function (): void {
    $this->jobs->queue('write-line', [], null, new QueueOptions(delaySeconds: 90));

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->delay === 90);
});

it('queues at once without options, as before', function (): void {
    $this->jobs->queue('write-line');

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->delay === null);
});

it('queues one run while one with the same key waits, and gives back its ID with its payload kept', function (): void {
    $first = $this->jobs->queue('write-line', ['line' => 'first'], null, new QueueOptions(uniqueKey: 'batch'));
    $second = $this->jobs->queue('write-line', ['line' => 'second'], null, new QueueOptions(uniqueKey: 'batch'));

    expect($second)->toBe($first)
        ->and(TrackedJob::query()->where('owner', 'jobs')->count())->toBe(1)
        ->and(TrackedJob::query()->findOrFail($first)->payload)->toBe(['line' => 'first']);

    Queue::assertPushed(RunPluginJob::class, 1);
});

it('keeps keys apart, by key and by job', function (): void {
    $a = $this->jobs->queue('write-line', [], null, new QueueOptions(uniqueKey: 'a'));
    $b = $this->jobs->queue('write-line', [], null, new QueueOptions(uniqueKey: 'b'));
    $c = $this->jobs->queue('quiet-write', [], null, new QueueOptions(uniqueKey: 'a'));

    expect(array_unique([$a, $b, $c]))->toHaveCount(3);
});

it('queues a new run once the waiting one has started', function (): void {
    $first = $this->jobs->queue('write-line', [], null, new QueueOptions(uniqueKey: 'batch'));
    TrackedJob::query()->whereKey($first)->update(['state' => JobState::Running->value]);

    $next = $this->jobs->queue('write-line', [], null, new QueueOptions(uniqueKey: 'batch'));

    expect($next)->not->toBe($first)
        ->and(TrackedJob::query()->where('owner', 'jobs')->count())->toBe(2);
});

it('refuses a delay outside 0 to 86400 seconds, or a malformed key', function (int $delay, ?string $key): void {
    expect(fn () => new QueueOptions($delay, $key))->toThrow(InvalidArgumentException::class);
})->with([
    'negative' => [-1, null],
    'over a day' => [86_401, null],
    'upper case' => [0, 'Batch'],
    'empty' => [0, ''],
    'too long' => [0, str_repeat('a', 65)],
    'a space' => [0, 'a b'],
]);

it('takes the edges of the allowed delay and key', function (): void {
    expect((new QueueOptions(86_400, 'action:test-ping.1_x'))->delaySeconds)->toBe(86_400)
        ->and((new QueueOptions(0, str_repeat('a', 64)))->uniqueKey)->toHaveLength(64);
});

it('refuses, in the database, a second waiting run with the same key', function (): void {
    $this->jobs->queue('write-line', [], null, new QueueOptions(uniqueKey: 'batch'));
    $row = DB::table('tracked_jobs')->where('owner', 'jobs')->first();

    expect(fn () => DB::table('tracked_jobs')->insert([
        'id' => strtolower((string) Str::ulid()),
        'kind' => $row->kind,
        'owner' => 'jobs',
        'label' => 'By hand',
        'starter' => 'person',
        'started_by' => $this->admin->id,
        'run_type' => $row->run_type,
        'run_id' => 'write-line',
        'state' => 'waiting',
        'done' => 0,
        'payload' => '{}',
        'unique_key' => 'batch',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to retry a failed keyed run while another with its key waits, writing nothing', function (): void {
    $failed = $this->jobs->queue('write-line', ['line' => 'first'], null, new QueueOptions(uniqueKey: 'batch'));
    TrackedJob::query()->whereKey($failed)->update(['state' => JobState::Failed->value, 'ended_at' => now()]);
    $waiting = $this->jobs->queue('write-line', ['line' => 'second'], null, new QueueOptions(uniqueKey: 'batch'));
    Queue::fake();

    expect(fn () => app(RetryJob::class)->execute(new RetryJobInput($failed, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'A run of this job is already waiting.');

    expect(TrackedJob::query()->findOrFail($failed)->state)->toBe(JobState::Failed)
        ->and(TrackedJob::query()->where('owner', 'jobs')->where('state', JobState::Waiting->value)->pluck('id')->all())->toBe([$waiting]);
    Queue::assertNothingPushed();
});

it('retries a failed keyed run when nothing with its key waits', function (): void {
    $failed = $this->jobs->queue('write-line', [], null, new QueueOptions(uniqueKey: 'batch'));
    TrackedJob::query()->whereKey($failed)->update(['state' => JobState::Failed->value, 'ended_at' => now()]);

    app(RetryJob::class)->execute(new RetryJobInput($failed, (string) $this->admin->id));

    expect(TrackedJob::query()->findOrFail($failed)->state)->toBe(JobState::Waiting);
});
