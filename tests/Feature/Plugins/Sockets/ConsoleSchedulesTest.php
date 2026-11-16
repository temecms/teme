<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Plugins\Jobs\RunPluginJob;
use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['email' => 'admin@example.com'])->assignRole('administrator');
    $this->editor = User::factory()->create(['email' => 'editor@example.com'])->assignRole('editor');

    PluginFixtures::use('jobs', 'hello');
    PluginFixtures::installed(['tests/jobs' => 'jobs', 'tests/hello' => 'hello']);
});

afterEach(fn () => PluginFixtures::forget());

it('refuses, writing nothing, to enable a plugin with schedules without --as', function (): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/jobs', '--agree' => true])
        ->expectsOutputToContain('Jobs runs work on a schedule, and scheduled work runs as an administrator. Name one with --as=<email>.')
        ->assertFailed();

    expect(PluginRecord::query()->count())->toBe(0);
});

it('refuses, writing nothing, an --as that names no one who may manage plugins', function (string $email): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/jobs', '--agree' => true, '--as' => $email])
        ->expectsOutputToContain("No one who may manage plugins has the email {$email}.")
        ->assertFailed();

    expect(PluginRecord::query()->count())->toBe(0);
})->with(['editor@example.com', 'nobody@example.com']);

it('enables with --as, runs its schedules as that administrator, and logs that the operator named them', function (): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/jobs', '--agree' => true, '--as' => 'Admin@Example.com'])
        ->assertSuccessful();

    $record = PluginRecord::query()->where('name', 'tests/jobs')->sole();

    expect($record->consented_by)->toBe($this->admin->id)
        ->and(app(ScheduleRunner::class)->problem($record))->toBeNull();

    $event = DB::table('security_events')->where('type', 'plugin.enabled')->orderByDesc('id')->first();
    expect($event->user_id)->toBeNull()
        ->and(json_decode((string) $event->context, true)['schedules_as'] ?? null)->toBe((string) $this->admin->id);

    Queue::fake();
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    Queue::assertPushed(RunPluginJob::class, fn (RunPluginJob $job): bool => $job->actor->userId === (string) $this->admin->id);
    expect(TrackedJob::query()->where('owner', 'jobs')->sole()->started_by)->toBe($this->admin->id);
});

it('still enables a plugin without schedules without --as', function (): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/hello', '--agree' => true])->assertSuccessful();

    expect(PluginRecord::query()->where('name', 'tests/hello')->sole()->state)->toBe(PluginRecord::ENABLED);
});

it('says why a plugin’s schedules don’t run, the same on the Plugins screen and in teme:plugins', function (?int $consentedBy, string $why): void {
    $record = PluginRecord::factory()->create([
        'name' => 'tests/jobs',
        'code' => 'jobs',
        'version' => '1.0.0',
        'state' => PluginRecord::ENABLED,
        'consent' => ['capabilities' => ['jobs', 'http'], 'hosts' => ['api.example.com'], 'hooks' => []],
        'consented_by' => $consentedBy === 0 ? $this->editor->id : null,
    ]);

    $sentence = $why.' Disable it and enable it again from Site › Plugins, or with --as.';

    expect(app(ScheduleRunner::class)->problem($record->fresh()))->toBe($sentence)
        ->and(collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/jobs')['schedules'])->toBe($sentence);

    $this->artisan('teme:plugins')->expectsOutputToContain($why)->assertSuccessful();
})->with([
    'enabled from the console' => [null, 'Its schedules don’t run: it was enabled from the command line without naming an administrator.'],
    'the administrator lost plugins.manage' => [0, 'Its schedules don’t run: the administrator who enabled it can no longer manage plugins.'],
]);

it('says so when the administrator who enabled it no longer has an account', function (): void {
    $record = (new PluginRecord)->forceFill([
        'name' => 'tests/jobs',
        'code' => 'jobs',
        'state' => PluginRecord::ENABLED,
        'consented_by' => $this->admin->id + 1000,
    ]);

    expect(app(ScheduleRunner::class)->problem($record))
        ->toBe('Its schedules don’t run: the administrator who enabled it no longer has an account. Disable it and enable it again from Site › Plugins, or with --as.');
});

it('says nothing for a plugin whose schedules run, or one without schedules', function (): void {
    $jobs = PluginRecord::factory()->create(['name' => 'tests/jobs', 'code' => 'jobs', 'version' => '1.0.0', 'state' => PluginRecord::ENABLED, 'consented_by' => $this->admin->id]);
    $hello = PluginRecord::factory()->create(['name' => 'tests/hello', 'code' => 'hello', 'version' => '1.0.0', 'state' => PluginRecord::ENABLED, 'consented_by' => null]);

    expect(app(ScheduleRunner::class)->problem($jobs))->toBeNull()
        ->and(app(ScheduleRunner::class)->problem($hello))->toBeNull()
        ->and(collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/hello')['schedules'])->toBeNull();
});

it('skips a plugin whose schedules can’t run, and logs it at most once a day', function (): void {
    PluginRecord::factory()->create([
        'name' => 'tests/jobs',
        'code' => 'jobs',
        'version' => '1.0.0',
        'state' => PluginRecord::ENABLED,
        'consent' => ['capabilities' => ['jobs', 'http'], 'hosts' => ['api.example.com'], 'hooks' => []],
        'consented_by' => null,
    ]);
    Queue::fake();
    $logged = 0;
    Log::listen(function ($message) use (&$logged): void {
        if (str_contains((string) $message->message, 'Its schedules don’t run')) {
            $logged++;
        }
    });

    $this->travelTo('2026-11-15 12:00:00');
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();
    $this->travelTo('2026-11-15 18:00:00');
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    expect($logged)->toBe(1)
        ->and(TrackedJob::query()->count())->toBe(0);

    $this->travelTo('2026-11-16 12:00:01');
    $this->artisan('teme:plugins:run-due-schedules')->assertSuccessful();

    expect($logged)->toBe(2);
});

it('checks again, in EnablePlugin, that the administrator named may manage plugins, writing nothing', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $manifest = app(PluginRegistry::class)->find('tests/jobs')?->manifest;

    expect(fn () => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/jobs', '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], [], null, schedulesAs: (string) $this->editor->id)))
        ->toThrow(ValidationException::class, 'No one who may manage plugins has that account.');

    expect(PluginRecord::query()->count())->toBe(0);
});
