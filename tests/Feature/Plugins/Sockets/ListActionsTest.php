<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\Lists\ListActions;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RunListActionInput;
use App\Domain\Plugins\Operations\RunListAction;
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
use Illuminate\Validation\ValidationException;
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

    $this->author = User::factory()->create()->assignRole('author');
    $this->own = Content::factory()->create(['author_id' => $this->author->id]);
    $this->others = Content::factory()->create(['author_id' => $this->admin->id]);
    $this->manifestErrors = fn (array $actions): array => app(ContributionsValidator::class)->errors(['listActions' => $actions], '1.0.0');
});

afterEach(function (): void {
    Workbench::reset();
    PluginFixtures::forget();
});

it('lists the plugin’s list actions for the content lists, with their scope', function (): void {
    expect(app(ListActions::class)->all())->toHaveCount(1)
        ->and(app(ListActions::class)->find('workbench', 'ping-items'))
        ->toMatchArray(['row' => true, 'bulk' => true, 'job' => 'ping', 'confirm' => 'Ping the selected items now?']);
});

it('queues the job for one row as the person who asked, with the item in its payload', function (): void {
    $result = app(RunListAction::class)->execute(new RunListActionInput('workbench', 'ping-items', (string) $this->admin->id, [(string) $this->others->id]));

    expect($result)->toMatchArray(['label' => 'Ping these', 'skipped' => 0]);

    $job = TrackedJob::query()->sole();

    expect($job->owner)->toBe('workbench')
        ->and($job->started_by)->toBe($this->admin->id)
        ->and($job->payload)->toEqual(['action' => 'ping-items', 'items' => [(string) $this->others->id]]);

    // A list action is a person's own click, so its closing line reaches their inbox.
    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $pushed): bool => $pushed->actor->userId === (string) $this->admin->id
        && $pushed->notifyOnFinish === true);
});

it('leaves out the items the person can’t edit, queuing the rest, and says how many it left out', function (): void {
    $result = app(RunListAction::class)->execute(new RunListActionInput('workbench', 'ping-items', (string) $this->author->id, [(string) $this->own->id, (string) $this->others->id], bulk: true));

    expect($result['skipped'])->toBe(1)
        ->and(TrackedJob::query()->sole()->payload)->toEqual(['action' => 'ping-items', 'items' => [(string) $this->own->id]]);
});

it('refuses, queuing nothing, when the person can’t edit any of the items', function (): void {
    expect(fn () => app(RunListAction::class)->execute(new RunListActionInput('workbench', 'ping-items', (string) $this->author->id, [(string) $this->others->id], bulk: true)))
        ->toThrow(AuthorizationException::class)
        ->and(TrackedJob::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('refuses, queuing nothing, an action the plugin doesn’t declare', function (): void {
    expect(fn () => app(RunListAction::class)->execute(new RunListActionInput('workbench', 'other-thing', (string) $this->admin->id, [(string) $this->own->id])))
        ->toThrow(AuthorizationException::class);

    expect(TrackedJob::query()->count())->toBe(0);
});

it('refuses a row action given several items, and a bulk run of no items', function (): void {
    expect(fn () => app(RunListAction::class)->execute(new RunListActionInput('workbench', 'ping-items', (string) $this->admin->id, [(string) $this->own->id, (string) $this->others->id])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RunListAction::class)->execute(new RunListActionInput('workbench', 'ping-items', (string) $this->admin->id, [], bulk: true)))
        ->toThrow(ValidationException::class);

    expect(TrackedJob::query()->count())->toBe(0);
});

it('offers nothing for a plugin not granted jobs', function (): void {
    $record = PluginRecord::query()->where('name', 'tests/workbench')->sole();
    $record->consent = [...$record->consent, 'capabilities' => []];
    $record->save();
    app(PluginRecords::class)->changed();

    expect(app(ListActions::class)->all())->toBe([]);
});

it('refuses list actions the manifest can’t declare', function (array $actions, string $error): void {
    expect(($this->manifestErrors)($actions))->toContain($error);
})->with([
    'four actions' => [array_map(fn (int $n): array => ['id' => "go-{$n}", 'label' => 'Go', 'row' => true, 'job' => 'ping'], range(1, 4)), 'A plugin can add at most 3 list actions.'],
    'three row actions' => [array_map(fn (int $n): array => ['id' => "go-{$n}", 'label' => 'Go', 'row' => true, 'job' => 'ping'], range(1, 3)), 'A plugin can add at most 2 row actions.'],
    'neither row nor bulk' => [[['id' => 'go', 'label' => 'Go', 'job' => 'ping']], 'A list action must be offered on rows, in bulk, or both.'],
    'no job' => [[['id' => 'go', 'label' => 'Go', 'row' => true]], 'A list action must name the job it queues.'],
]);

it('queues a row action posted from a content list, as the person who clicked', function (): void {
    $this->actingAs($this->admin)
        ->post(route('plugins.list-actions.run', ['code' => 'workbench', 'action' => 'ping-items']), ['item' => (string) $this->others->id])
        ->assertSessionHasNoErrors();

    expect(TrackedJob::query()->sole()->started_by)->toBe($this->admin->id);
});

it('lets an author queue a list action on their own item, leaving out someone else’s', function (): void {
    $this->actingAs($this->author)
        ->post(route('plugins.list-actions.run', ['code' => 'workbench', 'action' => 'ping-items']), ['items' => [(string) $this->own->id, (string) $this->others->id], 'bulk' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    expect(TrackedJob::query()->sole()->payload)->toEqual(['action' => 'ping-items', 'items' => [(string) $this->own->id]])
        ->and(TrackedJob::query()->sole()->started_by)->toBe($this->author->id);
});

it('counts a repeated item once, so nothing is reported left out', function (): void {
    $result = app(RunListAction::class)->execute(new RunListActionInput('workbench', 'ping-items', (string) $this->admin->id, [(string) $this->others->id, (string) $this->others->id], bulk: true));

    expect($result['skipped'])->toBe(0)
        ->and(TrackedJob::query()->sole()->payload)->toEqual(['action' => 'ping-items', 'items' => [(string) $this->others->id]]);
});

it('gives the Tools › Jobs link only to someone who can open it', function (): void {
    $this->actingAs($this->author)
        ->post(route('plugins.list-actions.run', ['code' => 'workbench', 'action' => 'ping-items']), ['item' => (string) $this->own->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Ping these queued.']);
});

it('refuses, at enable, a list action naming a job the plugin doesn’t declare', function (): void {
    $errors = app(Contributions::class)->add('tests/undeclared', 'undeclared', 'Undeclared', '1.0.0', ['listActions' => [['id' => 'go', 'label' => 'Go', 'row' => true, 'job' => 'nope']]], new stdClass, []);

    expect($errors)->toContain('Its action "go" queues the job "nope", which it doesn’t declare.');
});

it('refuses a list action from a plugin without the jobs capability', function (): void {
    expect(app(ContributionsValidator::class)->errors(['listActions' => [['id' => 'go', 'label' => 'Go', 'row' => true, 'job' => 'ping']]], '1.0.0', ['content.read']))
        ->toContain('A plugin’s list actions need jobs.');
});
