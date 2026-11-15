<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginHttp;
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
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\Jobs;
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
        ->toThrow(InvalidArgumentException::class)
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
    $short = new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14']]), 10);
    $long = app(PluginDoors::class)->for('tests/jobs', 'jobs', ['http'], ['api.example.com'], 300)[OutboundHttp::class];

    expect($ceiling($short))->toBe(10)
        ->and($ceiling($long))->toBe(300);
});
