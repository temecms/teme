<?php

declare(strict_types=1);

use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginSettingsWrites;
use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\SafeMode;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\Models\SettingsChange;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Settings\PluginSettingsWriter;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Workbench\Workbench;

uses(RefreshDatabase::class);

// The fixture's classes, loaded before a test sets what its job does.
foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/workbench/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Workbench::reset();

    PluginFixtures::use('workbench');
    PluginFixtures::installed(['tests/workbench' => 'workbench']);

    $manifest = app(PluginRegistry::class)->find('tests/workbench')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/workbench',
        $manifest?->version() ?? '1.0.0',
        $manifest?->capabilities() ?? [],
        $manifest?->hosts() ?? [],
        $manifest?->hooks() ?? [],
        (string) $this->admin->id,
    ));

    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    $this->door = new PluginSettingsWrites('workbench');

    /** Runs the door as the job of this actor, as RunPluginJob would. */
    $this->asJob = function (JobActor $actor, Closure $then): mixed {
        app(CurrentJobActor::class)->set($actor);

        try {
            return $then();
        } finally {
            app(CurrentJobActor::class)->clear();
        }
    };

    $this->nothingWritten = function (): void {
        expect(SettingsGroupRecord::query()->where('group_key', 'plugin.workbench')->exists())->toBeFalse()
            ->and(SettingsChange::query()->where('group_key', 'plugin.workbench')->count())->toBe(0);
    };
});

afterEach(function (): void {
    Workbench::reset();
    PluginFixtures::forget();
});

it('lets a scheduled job, run as the administrator who enabled the plugin, save its own text setting, recorded as through the plugin', function (): void {
    Event::fake([SettingsChanged::class]);
    Queue::fake();
    $adminId = (string) $this->admin->id;
    Workbench::$onPing = fn (PluginSettingsWriter $settings) => $settings->write(['site_key' => 'k3y-from-the-job'], $adminId);

    app(DueSchedules::class)->run();

    $job = null;
    Queue::assertPushed(RunPluginJob::class, function (RunPluginJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $job->handle(app(PluginRegistry::class), app(PluginLoader::class), app(SafeMode::class), app(CancelJob::class), app(FailJob::class), app(FinishJob::class), app(ReportJobProgress::class));

    $change = SettingsChange::query()->where('group_key', 'plugin.workbench')->sole();

    expect(app(SettingsReader::class)->string('plugin.workbench', 'site_key'))->toBe('k3y-from-the-job')
        ->and($change->actor_id)->toBe($this->admin->id)
        ->and($change->origin)->toBe('plugin:workbench')
        ->and($change->changed_keys)->toBe(['site_key']);

    Event::assertDispatched(SettingsChanged::class, fn (SettingsChanged $event): bool => $event->group === 'plugin.workbench' && $event->actorId === $adminId);
});

it('announces the change only after its transaction commits', function (): void {
    Event::fake([SettingsChanged::class]);

    DB::beginTransaction();
    ($this->asJob)(JobActor::person($this->admin->id), fn () => $this->door->write(['site_key' => 'inside'], (string) $this->admin->id));
    Event::assertNotDispatched(SettingsChanged::class);
    DB::rollBack();

    Event::assertNotDispatched(SettingsChanged::class);
    ($this->nothingWritten)();
});

it('writes for the signed-in person of a request', function (): void {
    $this->actingAs($this->admin);

    $this->door->write(['batch' => 25], (string) $this->admin->id);

    expect(app(SettingsReader::class)->int('plugin.workbench', 'batch'))->toBe(25);
});

it('refuses, writing nothing, when no one is running the work', function (): void {
    expect(fn () => $this->door->write(['site_key' => 'nobody'], (string) $this->admin->id))
        ->toThrow(WriteRefused::class);

    ($this->nothingWritten)();
});

it('refuses, writing nothing, a person other than the job’s', function (): void {
    $other = User::factory()->create()->assignRole('administrator');

    expect(fn () => ($this->asJob)(JobActor::person($this->admin->id), fn () => $this->door->write(['site_key' => 'other'], (string) $other->id)))
        ->toThrow(WriteRefused::class, 'A plugin writes only for the person who started this work.');

    ($this->nothingWritten)();
});

it('refuses, writing nothing, in the operator’s job', function (): void {
    expect(fn () => ($this->asJob)(JobActor::operator(), fn () => $this->door->write(['site_key' => 'operator'], (string) $this->admin->id)))
        ->toThrow(WriteRefused::class);

    ($this->nothingWritten)();
});

it('refuses, writing nothing, a person who can’t manage plugins', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ($this->asJob)(JobActor::person($editor->id), fn () => $this->door->write(['site_key' => 'editor'], (string) $editor->id)))
        ->toThrow(WriteRefused::class, 'Not permitted to change these settings.');

    ($this->nothingWritten)();
});

it('refuses, writing nothing, for a disabled plugin', function (): void {
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/workbench', null));

    expect(fn () => ($this->asJob)(JobActor::person($this->admin->id), fn () => $this->door->write(['site_key' => 'disabled'], (string) $this->admin->id)))
        ->toThrow(WriteRefused::class, 'Not permitted to change these settings.');

    ($this->nothingWritten)();
});

it('refuses, writing nothing, a key it doesn’t declare', function (): void {
    expect(fn () => ($this->asJob)(JobActor::person($this->admin->id), fn () => $this->door->write(['site_key' => 'fine', 'unknown' => 'x'], (string) $this->admin->id)))
        ->toThrow(WriteRefused::class, 'This setting does not exist.');

    ($this->nothingWritten)();
});

it('refuses, writing nothing, a secret, url or urls setting', function (string $key, mixed $value): void {
    expect(fn () => ($this->asJob)(JobActor::person($this->admin->id), fn () => $this->door->write([$key => $value], (string) $this->admin->id)))
        ->toThrow(WriteRefused::class);

    ($this->nothingWritten)();
})->with([
    'secret' => ['token', 'shh'],
    'url' => ['endpoint', 'https://hub.example.net/'],
    'urls' => ['hubs', ['https://hub.example.net/']],
]);

it('refuses, writing nothing, a value its rules refuse', function (): void {
    expect(fn () => ($this->asJob)(JobActor::person($this->admin->id), fn () => $this->door->write(['batch' => 500], (string) $this->admin->id)))
        ->toThrow(WriteRefused::class);

    ($this->nothingWritten)();
});

it('never reaches another plugin’s group', function (): void {
    $other = new PluginSettingsWrites('columns');

    expect(fn () => ($this->asJob)(JobActor::person($this->admin->id), fn () => $other->write(['site_key' => 'theirs'], (string) $this->admin->id)))
        ->toThrow(WriteRefused::class);

    expect(SettingsGroupRecord::query()->count())->toBe(0);
});

it('is offered to a plugin that declares settings', function (): void {
    $plugin = app(LoadedPlugins::class)->running()['tests/workbench'] ?? null;

    expect($plugin)->toBeInstanceOf(Workbench::class)
        ->and($plugin->settings)->toBeInstanceOf(PluginSettingsWrites::class)
        ->and(PluginRecord::query()->where('code', 'workbench')->sole()->state)->toBe(PluginRecord::ENABLED);
});
