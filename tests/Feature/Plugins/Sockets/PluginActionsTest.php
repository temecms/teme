<?php

declare(strict_types=1);

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RunPluginActionInput;
use App\Domain\Plugins\Operations\RunPluginAction;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Workbench\Workbench;

uses(RefreshDatabase::class);

// The fixture's classes, loaded before a test resets them.
foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/workbench/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Workbench::reset();
    Queue::fake();

    PluginFixtures::use('workbench');
    $manifest = app(PluginRegistry::class)->find('tests/workbench')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/workbench', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], (string) $this->admin->id));
    app(PluginLoader::class)->load();

    $this->click = fn (User $person, string $action = 'test-ping') => $this->actingAs($person)
        ->post(route('plugins.actions.run', ['code' => 'workbench', 'action' => $action]));

    $this->manifestErrors = fn (array $actions): array => app(ContributionsValidator::class)->errors(['actions' => $actions], '1.0.0');
});

afterEach(function (): void {
    Workbench::reset();
    PluginFixtures::forget();
});

it('offers the plugin’s actions on its settings page', function (): void {
    $this->actingAs($this->admin)->get(route('settings.show', ['group' => 'plugin.workbench']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pluginActions', [['id' => 'test-ping', 'label' => 'Send a test ping', 'description' => 'Tells the service about the home page now.']]));
});

it('queues the action’s job as the person who clicked, with the action in its payload, and says where to follow it', function (): void {
    ($this->click)($this->admin)
        ->assertRedirect(route('settings.show', ['group' => 'plugin.workbench']))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Send a test ping queued. Follow it in Tools › Jobs.',
            'link' => ['label' => 'Tools › Jobs', 'href' => route('tools.jobs.index', [], false)],
        ]);

    $job = TrackedJob::query()->sole();

    expect($job->owner)->toBe('workbench')
        ->and($job->run_id)->toBe('ping')
        ->and($job->label)->toBe('Send a test ping')
        ->and($job->state)->toBe(JobState::Waiting)
        ->and($job->started_by)->toBe($this->admin->id)
        ->and($job->payload)->toBe(['action' => 'test-ping']);

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $pushed): bool => $pushed->actor->userId === (string) $this->admin->id);
});

it('returns the waiting job to a second click, queuing nothing more', function (): void {
    $first = app(RunPluginAction::class)->execute(new RunPluginActionInput('workbench', 'test-ping', (string) $this->admin->id));
    $second = app(RunPluginAction::class)->execute(new RunPluginActionInput('workbench', 'test-ping', (string) $this->admin->id));

    expect($second['job'])->toBe($first['job'])
        ->and(TrackedJob::query()->count())->toBe(1);

    Queue::assertPushed(RunPluginJob::class, 1);
});

it('refuses, queuing nothing, a person who can’t manage plugins', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput('workbench', 'test-ping', (string) $editor->id)))
        ->toThrow(AuthorizationException::class);
    ($this->click)($editor)->assertForbidden();

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses, queuing nothing, for a disabled plugin', function (): void {
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/workbench', (string) $this->admin->id));

    ($this->click)($this->admin)->assertInertiaFlash('toast.type', 'error');

    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput('workbench', 'test-ping', (string) $this->admin->id)))
        ->toThrow(AuthorizationException::class)
        ->and(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses, queuing nothing, for a plugin not granted jobs', function (): void {
    $record = PluginRecord::query()->where('name', 'tests/workbench')->sole();
    $record->consent = [...$record->consent, 'capabilities' => []];
    $record->save();
    app(PluginRecords::class)->changed();

    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput('workbench', 'test-ping', (string) $this->admin->id)))
        ->toThrow(AuthorizationException::class)
        ->and(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses, queuing nothing, an action the plugin doesn’t declare', function (): void {
    expect(fn () => app(RunPluginAction::class)->execute(new RunPluginActionInput('workbench', 'other-thing', (string) $this->admin->id)))
        ->toThrow(AuthorizationException::class);
    ($this->click)($this->admin, 'other-thing')
        ->assertRedirect(route('settings.show', ['group' => 'plugin.workbench']))
        ->assertInertiaFlash('toast.type', 'error');

    expect(TrackedJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses actions the manifest can’t declare', function (array $actions, string $error): void {
    expect(($this->manifestErrors)($actions))->toContain($error);
})->with([
    'four actions' => [array_map(fn (int $n): array => ['id' => "go-{$n}", 'label' => 'Go', 'job' => 'ping'], range(1, 4)), 'A plugin can add at most 3 actions.'],
    'a bad id' => [[['id' => 'Go!', 'label' => 'Go', 'job' => 'ping']], 'An action’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.'],
    'a long label' => [[['id' => 'go', 'label' => str_repeat('a', 41), 'job' => 'ping']], 'An action needs a label of 1 to 40 characters.'],
    'a long description' => [[['id' => 'go', 'label' => 'Go', 'description' => str_repeat('a', 161), 'job' => 'ping']], 'An action’s description must be text of at most 160 characters.'],
    'no job' => [[['id' => 'go', 'label' => 'Go']], 'An action must name the job it queues.'],
    'a shared id' => [[['id' => 'go', 'label' => 'Go', 'job' => 'ping'], ['id' => 'go', 'label' => 'Again', 'job' => 'ping']], 'Two actions can’t share the id go.'],
]);

it('doesn’t start a plugin whose action names a job it doesn’t declare', function (): void {
    $manifest = PluginFixtures::manifest('workbench');
    $manifest['contributes']['actions'][0]['job'] = 'missing';
    PluginFixtures::use(PluginFixtures::copy('workbench', ['contributes' => $manifest['contributes']]));
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->failure('tests/workbench'))->toBe('Its action "test-ping" queues the job "missing", which it doesn’t declare.');
});
