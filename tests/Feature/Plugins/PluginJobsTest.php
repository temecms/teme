<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Jobs\Operations\RetryJob;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Jobs\PluginJobContext;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\JobStopped;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    Http::preventStrayRequests();
    app()->instance(HostResolver::class, FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));

    PluginFixtures::use('jobs');
    PluginFixtures::installed(['tests/jobs' => 'jobs']);

    $this->enable = function (): string {
        $plugin = app(PluginRegistry::class)->find('tests/jobs');
        $manifest = $plugin?->manifest;

        return app(EnablePlugin::class)->execute(new EnablePluginInput(
            'tests/jobs',
            $manifest?->version() ?? '1.0.0',
            $manifest?->capabilities() ?? [],
            $manifest?->hosts() ?? [],
            $manifest?->hooks() ?? [],
            null,
        ));
    };

    $this->load = function (): object {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();

        return app(LoadedPlugins::class)->running()['tests/jobs']
            ?? throw new RuntimeException('The jobs plugin did not start.');
    };

    $this->jobs = function (): Jobs {
        $instance = ($this->load)();

        return (new ReflectionClass($instance))->getProperty('jobs')->getValue($instance);
    };

    $this->run = function (string $key, string $id): void {
        (new RunPluginJob('jobs', $key, $id, JobActor::operator()))->handle(
            app(PluginRegistry::class),
            app(PluginLoader::class),
            app(SafeMode::class),
            app(CancelJob::class),
            app(FailJob::class),
            app(FinishJob::class),
            app(ReportJobProgress::class),
        );
    };
});

afterEach(fn () => PluginFixtures::forget());

it('queues a granted job, runs it, and finishes with a line in the plugin’s table', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'hello from the plugin']);

    expect(TrackedJob::query()->findOrFail($id)->only(['kind', 'owner', 'label', 'state', 'run_type']))
        ->toEqual([
            'kind' => 'plugin-job',
            'owner' => 'jobs',
            'label' => 'Write a line',
            'state' => JobState::Waiting,
            'run_type' => 'plugin-job',
        ]);

    Queue::assertPushed(RunPluginJob::class);
    ($this->run)('write-line', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished)
        ->and(DB::table('jobs_lines')->pluck('line')->all())->toBe(['hello from the plugin']);
});

it('offers no Jobs door when jobs isn’t granted, and a hand-made run cancels without writing', function (): void {
    ($this->enable)();

    $doors = app(PluginDoors::class)->for('tests/jobs', 'jobs', ['http'], ['api.example.com']);
    expect($doors)->not->toHaveKey(Jobs::class)
        ->and($doors)->toHaveKey(OutboundHttp::class);

    $record = PluginRecord::query()->where('name', 'tests/jobs')->sole();
    $record->consent = ['capabilities' => ['http'], 'hosts' => ['api.example.com'], 'hooks' => []];
    $record->save();

    $id = app(StartJob::class)->execute(new StartJobInput(
        kind: 'plugin-job',
        label: 'Write a line',
        runType: 'plugin-job',
        runId: 'write-line',
        owner: 'jobs',
        payload: ['line' => 'should not write'],
    ));

    ($this->run)('write-line', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('cancels a disabled plugin’s waiting run', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'later']);

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null));
    ($this->run)('write-line', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('cancels the run of a plugin whose update waits for consent, as it does a disabled one', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'later']);

    // The update asks for more than was agreed: the plugin keeps jobs but no longer has http.
    $record = PluginRecord::query()->where('name', 'tests/jobs')->sole();
    $record->consent = [...$record->consent, 'capabilities' => ['jobs']];
    $record->save();
    ($this->run)('write-line', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('runs nothing in safe mode', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'safe']);

    PluginFixtures::serving('admin/plugins', function () use ($id): void {
        ($this->run)('write-line', $id);
    });

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('fails a throwing handler with the first line of its message', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('throw', []);

    (new RunPluginJob('jobs', 'throw', $id, JobActor::operator()))
        ->failed(new RuntimeException("The workspace answered nothing.\nAnd more detail."));

    $row = TrackedJob::query()->findOrFail($id);
    expect($row->state)->toBe(JobState::Failed)
        ->and($row->message)->toBe('The workspace answered nothing.');
});

it('refuses a payload over 64 KB or with an object', function (): void {
    ($this->enable)();
    Queue::fake();
    $jobs = ($this->jobs)();

    expect(fn () => $jobs->queue('write-line', ['line' => str_repeat('a', 70_000)]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $jobs->queue('write-line', ['line' => new stdClass]))
        ->toThrow(InvalidArgumentException::class, 'A job’s payload must be JSON-ready data.')
        ->and(fn () => $jobs->queue('write-line', ['one', 'two']))
        ->toThrow(InvalidArgumentException::class, 'A job’s payload must be JSON-ready data.')
        ->and(DB::table('tracked_jobs')->count())->toBe(0);
});

it('fails a key with no handler without retry, and the fault shows on the Plugins screen', function (): void {
    $broken = PluginFixtures::copy('jobs', [
        'plugin' => 'Tests\\Fixtures\\Plugins\\Jobs\\BrokenJobsPlugin',
    ], files: [
        'src/BrokenJobsPlugin.php' => <<<'PHP'
<?php

namespace Tests\Fixtures\Plugins\Jobs;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;

final class BrokenJobsPlugin implements Plugin, PluginHasJobs
{
    public function __construct(public readonly Jobs $jobs, public readonly OutboundHttp $http) {}

    public function register(Hooks $hooks): void {}

    public function jobs(): array
    {
        return [
            'write-line' => new WriteLineHandler,
            'quiet-write' => new QuietWriteHandler,
            'progress-write' => new ProgressWriteHandler,
            'long-call' => new LongCallHandler($this->http),
        ];
    }
}
PHP,
    ]);
    PluginFixtures::use($broken);
    PluginFixtures::installed(['tests/jobs' => $broken]);
    ($this->enable)();
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    $row = collect(app(PluginList::class)->all())->first(fn ($r) => $r->name === 'tests/jobs');
    expect($row?->reason)->toContain('throw');
});

it('clamps outbound timeouts to the door’s ceiling, 300 seconds inside a long job', function (): void {
    $ceiling = fn (object $http): int => (new ReflectionProperty($http, 'mostSeconds'))->getValue($http);
    $short = new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14']]), mostSeconds: 10);
    $long = app(PluginDoors::class)->for('tests/jobs', 'jobs', ['http'], ['api.example.com'], httpSeconds: 300)[OutboundHttp::class];

    expect($ceiling($short))->toBe(10)
        ->and($ceiling($long))->toBe(300);
});

it('cancels waiting and running plugin jobs after disable commits', function (): void {
    ($this->enable)();
    Queue::fake();
    $waiting = ($this->jobs)()->queue('write-line', ['line' => 'waiting']);
    $running = ($this->jobs)()->queue('write-line', ['line' => 'running']);
    TrackedJob::query()->whereKey($running)->update(['state' => JobState::Running]);
    $finished = app(StartJob::class)->execute(new StartJobInput(
        kind: 'plugin-job',
        label: 'Already done',
        runType: 'plugin-job',
        runId: 'write-line',
        owner: 'jobs',
        payload: [],
    ));
    TrackedJob::query()->whereKey($finished)->update(['state' => JobState::Finished, 'ended_at' => now()]);

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null));

    expect(TrackedJob::query()->findOrFail($waiting)->state)->toBe(JobState::Cancelled)
        ->and(TrackedJob::query()->findOrFail($running)->state)->toBe(JobState::Cancelled)
        ->and(TrackedJob::query()->findOrFail($finished)->state)->toBe(JobState::Finished);
});

it('cancels nothing when disable is refused', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'keep']);
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null)))
        ->toThrow(AuthorizationException::class);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Waiting)
        ->and(PluginRecord::query()->where('name', 'tests/jobs')->value('state'))->toBe(PluginRecord::ENABLED);
});

it('still disables when a CancelJob finds the run already ended', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'race']);

    // Between DisablePlugin listing the id and CancelJob's lock, end the row so
    // CancelJob throws ValidationException — disable must still finish.
    TrackedJob::retrieved(function (TrackedJob $job) use ($id): void {
        static $ended = false;

        if ($ended || (string) $job->getKey() !== $id) {
            return;
        }

        $ended = true;
        DB::table('tracked_jobs')->where('id', $id)->update([
            'state' => JobState::Cancelled->value,
            'message' => 'ended meanwhile',
            'ended_at' => now(),
        ]);
    });

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null));

    expect(PluginRecord::query()->where('name', 'tests/jobs')->value('state'))->toBe(PluginRecord::DISABLED);
});

it('runs nothing after CancelJob ended the tracked job', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('quiet-write', ['line' => 'should not write']);
    app(CancelJob::class)->execute(new CancelJobInput($id, null));

    ($this->run)('quiet-write', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('runs nothing when the job was cancelled by disable then the plugin was enabled again', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('quiet-write', ['line' => 'should not write']);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null));
    ($this->enable)();

    ($this->run)('quiet-write', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('stops progress after the plugin is disabled', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('progress-write', ['line' => 'should not write']);
    TrackedJob::query()->whereKey($id)->update(['state' => JobState::Running]);

    $context = new PluginJobContext(
        $id,
        ['line' => 'should not write'],
        1,
        'tests/jobs',
        JobActor::operator(),
        app(ReportJobProgress::class),
    );

    $context->progress(1, 2, 'First');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/jobs', null));

    expect(fn () => $context->progress(2, 2, 'Second'))->toThrow(JobStopped::class)
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('queues with the declared tries and backoff', function (): void {
    ($this->enable)();
    Queue::fake();

    ($this->jobs)()->queue('write-line', ['line' => 'tries']);
    ($this->jobs)()->queue('throw', []);

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->jobKey === 'write-line'
        && $job->tries === 2
        && $job->backoff === [1, 2]);
    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->jobKey === 'throw'
        && $job->tries === 2
        && $job->backoff === [1]);
});

it('retries from Tools › Jobs with the saved payload', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = ($this->jobs)()->queue('write-line', ['line' => 'from the saved payload']);
    TrackedJob::query()->whereKey($id)->update([
        'state' => JobState::Failed,
        'message' => 'It failed.',
        'ended_at' => now(),
    ]);

    app(RetryJob::class)->execute(new RetryJobInput($id, null));

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->trackedJobId === $id
        && $job->jobKey === 'write-line');

    ($this->run)('write-line', $id);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished)
        ->and(DB::table('jobs_lines')->pluck('line')->all())->toBe(['from the saved payload']);
});

it('fails a missing handler without retry', function (): void {
    ($this->enable)();
    Queue::fake();
    $id = app(StartJob::class)->execute(new StartJobInput(
        kind: 'plugin-job',
        label: 'Missing',
        runType: 'plugin-job',
        runId: 'no-such-handler',
        owner: 'jobs',
        payload: ['line' => 'no'],
    ));

    $job = new RunPluginJob('jobs', 'no-such-handler', $id, JobActor::operator());
    $job->handle(
        app(PluginRegistry::class),
        app(PluginLoader::class),
        app(SafeMode::class),
        app(CancelJob::class),
        app(FailJob::class),
        app(FinishJob::class),
        app(ReportJobProgress::class),
    );

    expect($job->tries)->toBe(1)
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Failed)
        ->and(TrackedJob::query()->findOrFail($id)->message)->toContain('no-such-handler')
        ->and(DB::table('jobs_lines')->count())->toBe(0);
});

it('builds a long job with a 300-second outbound ceiling and 1800-second timeout', function (): void {
    ($this->enable)();
    Queue::fake();
    ($this->jobs)()->queue('long-call', []);

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->jobKey === 'long-call'
        && $job->timeout === 1800);

    $doors = app(PluginDoors::class)->for('tests/jobs', 'jobs', ['http'], ['api.example.com'], httpSeconds: 300);
    $ceiling = (new ReflectionProperty($doors[OutboundHttp::class], 'mostSeconds'))->getValue($doors[OutboundHttp::class]);

    expect($ceiling)->toBe(300);
});

it('runs a job queued by a person as that person', function (): void {
    ($this->enable)();
    Queue::fake();
    $person = User::factory()->create()->assignRole('administrator');
    Auth::login($person);

    $id = ($this->jobs)()->queue('write-line', ['line' => 'as the person']);

    Queue::assertPushed(RunPluginJob::class, function (RunPluginJob $job) use ($person): bool {
        expect($job->actor->userId)->toBe((string) $person->id)
            ->and($job->actor->isOperator())->toBeFalse();

        app()->instance(Elevation::class, new DeniedElevation);
        $job->actor->bind();
        expect(app(Elevation::class))->toBeInstanceOf(DeniedElevation::class);

        return true;
    });

    expect(TrackedJob::query()->findOrFail($id)->started_by)->toBe($person->id);

    (new RunPluginJob('jobs', 'write-line', $id, JobActor::person($person->id)))->handle(
        app(PluginRegistry::class),
        app(PluginLoader::class),
        app(SafeMode::class),
        app(CancelJob::class),
        app(FailJob::class),
        app(FinishJob::class),
        app(ReportJobProgress::class),
    );

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished)
        ->and(DB::table('jobs_lines')->pluck('line')->all())->toBe(['as the person']);
});

it('tells the write doors who queued the job while its handler runs, and forgets after', function (): void {
    ($this->enable)();
    Queue::fake();
    $person = User::factory()->create()->assignRole('contributor');
    Auth::login($person);
    $id = ($this->jobs)()->queue('write-line', ['line' => 'seen']);
    Auth::logout();
    $seen = 'nothing';

    DB::listen(function ($query) use (&$seen): void {
        if (str_starts_with(strtolower($query->sql), 'insert into "jobs_lines"')) {
            $seen = app(CurrentJobActor::class)->get()?->userId;
        }
    });

    (new RunPluginJob('jobs', 'write-line', $id, JobActor::person($person->id)))->handle(
        app(PluginRegistry::class),
        app(PluginLoader::class),
        app(SafeMode::class),
        app(CancelJob::class),
        app(FailJob::class),
        app(FinishJob::class),
        app(ReportJobProgress::class),
    );

    expect($seen)->toBe((string) $person->id)
        ->and(app(CurrentJobActor::class)->get())->toBeNull();
});
