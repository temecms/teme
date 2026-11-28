<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Jobs\PluginJobContext;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Teme\Contracts\Jobs\Jobs;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');

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

    Role::findByName('editor')->givePermissionTo(Permission::create(['name' => 'plugin.billboard.manage']));

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
});

afterEach(fn () => PluginFixtures::forget());

it('finishes with Finished when the handler sets no summary', function (): void {
    Queue::fake();
    Auth::login($this->admin);
    $id = ($this->jobs)()->queue('write-line', ['line' => 'plain']);

    ($this->handle)(Queue::pushed(RunPluginJob::class)->first());

    expect(TrackedJob::query()->findOrFail($id)->only(['state', 'message']))
        ->toEqual(['state' => JobState::Finished, 'message' => 'Finished.']);
});

it('keeps a summary as the closing line instead of replacing the last progress with Finished', function (): void {
    Queue::fake();
    Auth::login($this->admin);
    $id = ($this->jobs)()->queue('summarise', []);

    ($this->handle)(Queue::pushed(RunPluginJob::class)->first());

    expect(TrackedJob::query()->findOrFail($id)->only(['state', 'message']))
        ->toEqual(['state' => JobState::Finished, 'message' => 'Wrote the line.']);
});

it('gives the run only the plugin’s own permission ids the person holds, never a role or another plugin’s', function (User $person, array $held): void {
    Queue::fake();
    Auth::login($person);
    ($this->jobs)()->queue('list-permissions', []);

    ($this->handle)(Queue::pushed(RunPluginJob::class)->first());

    expect(json_decode((string) DB::table('jobs_lines')->value('line'), true, flags: JSON_THROW_ON_ERROR))->toBe($held);
})->with([
    'an editor, who holds manage-lines and another plugin’s permission' => [fn () => $this->editor, ['manage-lines']],
    'an administrator, who holds both' => [fn () => $this->admin, ['manage-lines', 'view-all']],
]);

it('answers no permissions for a run of the operator’s', function (): void {
    $context = new PluginJobContext(
        '01k0000000000000000000000a',
        [],
        1,
        'tests/jobs',
        'jobs',
        JobActor::operator(),
        app(ReportJobProgress::class),
    );

    expect($context->permissions())->toBe([]);
});

it('cuts an over-long or multi-line summary to the first line of at most 500 characters, and empty to Finished', function (): void {
    $context = new PluginJobContext(
        '01k0000000000000000000000a',
        [],
        1,
        'tests/jobs',
        'jobs',
        JobActor::person($this->admin->id),
        app(ReportJobProgress::class),
    );

    $context->summary("First line that is fine.\nSecond line dropped.");
    expect($context->closingMessage())->toBe('First line that is fine.');

    $context->summary(str_repeat('a', 600));
    expect($context->closingMessage())->toBe(str_repeat('a', 500));

    $context->summary('   ');
    expect($context->closingMessage())->toBe('Finished.');
});
