<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/jobs',
        $manifest?->version() ?? '1.0.0',
        $manifest?->capabilities() ?? [],
        $manifest?->hosts() ?? [],
        $manifest?->hooks() ?? [],
        (string) $this->admin->id,
    ));

    $this->jobs = function (): Jobs {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
        $plugin = app(LoadedPlugins::class)->running()['tests/jobs'] ?? throw new RuntimeException('The jobs plugin did not start.');

        return (new ReflectionClass($plugin))->getProperty('jobs')->getValue($plugin);
    };

    $this->handle = function (RunPluginJob $job): void {
        $job->handle(
            app(PluginRegistry::class),
            app(PluginLoader::class),
            app(SafeMode::class),
            app(CancelJob::class),
            app(FailJob::class),
            app(FinishJob::class),
            app(ReportJobProgress::class),
        );
    };

    $this->asGuest = function (): void {
        Auth::logout();
        app()->instance(Elevation::class, new DeniedElevation);
        app(CurrentJobActor::class)->clear();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('queues with no signed-in person as the administrator who enabled the plugin', function (): void {
    Queue::fake();
    ($this->asGuest)();

    $id = ($this->jobs)()->queue('write-line', ['line' => 'from a hook']);

    expect(TrackedJob::query()->findOrFail($id)->started_by)->toBe($this->admin->id);
    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->actor->userId === (string) $this->admin->id);
});

it('queues a nested job as the running job’s actor when CurrentJobActor is set', function (): void {
    Queue::fake();
    ($this->asGuest)();
    $editor = User::factory()->create()->assignRole('editor');
    app(CurrentJobActor::class)->set(JobActor::person($editor->id));

    $id = ($this->jobs)()->queue('write-line', ['line' => 'nested']);

    expect(TrackedJob::query()->findOrFail($id)->started_by)->toBe($editor->id);
    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->actor->userId === (string) $editor->id);
});

it('finishes a plugin job quietly with no inbox item, and fails loudly with one', function (): void {
    Queue::fake();
    ($this->asGuest)();

    $ok = ($this->jobs)()->queue('write-line', ['line' => 'ok']);
    ($this->handle)(Queue::pushed(RunPluginJob::class)->first());

    expect(TrackedJob::query()->findOrFail($ok)->state)->toBe(JobState::Finished)
        ->and(DB::table('inbox_items')->where('user_id', $this->admin->id)->count())->toBe(0);

    Queue::fake();
    $bad = ($this->jobs)()->queue('throw', []);
    (Queue::pushed(RunPluginJob::class)->first())->failed(new RuntimeException('The workspace answered nothing.'));

    expect(TrackedJob::query()->findOrFail($bad)->state)->toBe(JobState::Failed)
        ->and(DB::table('inbox_items')->where('user_id', $this->admin->id)->value('kind'))->toBe('job.failed');
});

it('takes a delay up to one day', function (): void {
    expect((new QueueOptions(delaySeconds: 86_400))->delaySeconds)->toBe(86_400);

    expect(fn () => new QueueOptions(delaySeconds: 86_401))->toThrow(InvalidArgumentException::class);
});
