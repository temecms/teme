<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
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
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\JobStopped;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    PluginFixtures::use('librarian');
    PluginFixtures::installed(['tests/librarian' => 'librarian']);

    $manifest = app(PluginRegistry::class)->find('tests/librarian')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/librarian',
        $manifest?->version() ?? '1.0.0',
        $manifest?->capabilities() ?? [],
        $manifest?->hosts() ?? [],
        $manifest?->hooks() ?? [],
        (string) $this->admin->id,
    ));

    $this->jobs = function (): Jobs {
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
        $plugin = app(LoadedPlugins::class)->running()['tests/librarian'] ?? throw new RuntimeException('The librarian did not start.');

        return $plugin->jobs;
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

    $this->pushed = function (): RunPluginJob {
        $pushed = Queue::pushed(RunPluginJob::class);

        expect($pushed)->toHaveCount(1);

        return $pushed->first();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('says a queued run acts for the person who queued it, and a door given that person writes for them', function (): void {
    Queue::fake();
    $editor = User::factory()->create(['name' => 'Eddie Editor'])->assignRole('editor');
    $this->actingAs($editor);
    $id = ($this->jobs)()->queue('write-as-person', ['title' => 'From the queue']);

    ($this->handle)(($this->pushed)());

    $written = Content::query()->where('title', 'From the queue')->sole();

    expect($written->author_id)->toBe($editor->id)
        ->and($written->workingRevision->via)->toBe('librarian')
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished);
});

it('says a scheduled run acts for the administrator who enabled the plugin', function (): void {
    Queue::fake();
    $this->travelTo('2026-11-16 12:00:00');

    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();
    ($this->handle)(($this->pushed)());

    expect(Content::query()->where('title', 'Written by the librarian')->sole()->author_id)->toBe($this->admin->id);
});

it('has no person to give for a run of the operator’s, and stops it', function (): void {
    $context = new PluginJobContext('01k0000000000000000000000a', [], 1, 'tests/librarian', JobActor::operator(), app(ReportJobProgress::class));

    expect(fn () => $context->personId())->toThrow(JobStopped::class, 'This run has no person to act for.')
        ->and((new PluginJobContext('01k0000000000000000000000a', [], 1, 'tests/librarian', JobActor::person($this->admin->id), app(ReportJobProgress::class)))->personId())
        ->toBe((string) $this->admin->id);
});
