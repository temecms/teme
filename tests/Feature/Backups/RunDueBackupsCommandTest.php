<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Operations\RequestBackup;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Jobs\Operations\Input\RetryJobInput;
use App\Domain\Jobs\Operations\RetryJob;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\VerifyDatabase;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->backups = null;
    $this->spare = null;
    VerifyDatabase::unnamed();
});

afterEach(function (): void {
    $this->spare?->drop();
    $this->backups?->delete();
    OtherSession::close();
});

it('starts the nightly backup, then the weekly prune, then the nightly read-back, by the schedule, when they’re due', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    $this->artisan('teme:backup:due')
        ->expectsOutput('Nightly backup: done.')
        ->expectsOutput('Weekly prune: done.')
        ->expectsOutput('Nightly read-back: done.')
        ->assertExitCode(0);

    $runs = BackupRun::query()->whereNull('parent_id')->orderBy('started_at')->get();

    expect($runs->pluck('kind')->all())->toBe([BackupRunKind::Backup, BackupRunKind::Prune, BackupRunKind::ReadBack])
        ->and($runs->every(fn (BackupRun $run): bool => $run->started_by === BackupTrigger::Schedule))->toBeTrue();

    // Nothing more is due this night.
    $this->artisan('teme:backup:due')->doesntExpectOutputToContain('Nightly')->assertExitCode(0);

    expect(BackupRun::query()->whereNull('parent_id')->count())->toBe(3);
});

it('starts the weekly test restore after the read-back when a spare database is named', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->spare = VerifyDatabase::make();

    $this->artisan('teme:backup:due')
        ->expectsOutput('Nightly read-back: done.')
        ->expectsOutput('Weekly test restore: done.')
        ->assertExitCode(0);

    expect(BackupRun::query()->whereNull('parent_id')->orderBy('started_at')->pluck('kind')->all())
        ->toBe([BackupRunKind::Backup, BackupRunKind::Prune, BackupRunKind::ReadBack, BackupRunKind::TestRestore]);
});

it('does nothing while the backup lock is held, or before backups are set up', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    OtherSession::holdBackupLock();

    $this->artisan('teme:backup:due')->doesntExpectOutputToContain('backup')->assertExitCode(0);

    OtherSession::close();
    $this->backups->places[0]->keys()->delete();

    $this->artisan('teme:backup:due')->doesntExpectOutputToContain('backup')->assertExitCode(0);

    expect(BackupRun::query()->count())->toBe(0);
});

it('is on the schedule every minute, never overlapping, on one server', function (): void {
    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'teme:backup:due'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('takes a waiting Back up now first, as the person who asked, and the night needs no other', function (): void {
    $this->seed(RoleSeeder::class);
    $this->backups = RunnableBackups::atFolders(1);
    $admin = User::factory()->create()->assignRole('administrator');
    $id = (string) app(RequestBackup::class)->execute(new RequestBackupInput((string) $admin->id));

    $this->artisan('teme:backup:due')
        ->expectsOutput('Back up now: done.')
        ->doesntExpectOutput('Nightly backup: done.')
        ->assertExitCode(0);

    $backups = BackupRun::query()->whereNull('parent_id')->where('kind', BackupRunKind::Backup->value)->get();

    expect($backups)->toHaveCount(1)
        ->and($backups[0]->started_by)->toBe(BackupTrigger::Person)
        ->and($backups[0]->actor_id)->toBe($admin->id)
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished);
});

it('records the nightly backup as the operator’s own tracked job', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    $this->artisan('teme:backup:due')->expectsOutput('Nightly backup: done.')->assertExitCode(0);

    $job = TrackedJob::query()->where('kind', 'backup')->sole();

    expect($job->label)->toBe('Nightly backup')
        ->and($job->starter)->toBe('operator')
        ->and($job->started_by)->toBeNull()
        ->and($job->state)->toBe(JobState::Finished);
});

it('leaves a waiting backup in line while the lock is held, and fails one that can’t run, in words', function (): void {
    $this->seed(RoleSeeder::class);
    $this->backups = RunnableBackups::atFolders(1);
    $admin = User::factory()->create()->assignRole('administrator');
    $id = (string) app(RequestBackup::class)->execute(new RequestBackupInput((string) $admin->id));
    OtherSession::holdBackupLock();

    $this->artisan('teme:backup:due')->assertExitCode(0);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Waiting);

    OtherSession::close();
    BackupDestination::query()->update(['paused_at' => now()]);

    $this->artisan('teme:backup:due')->expectsOutput('Back up now didn’t run: '.BackupPlaces::PAUSED)->assertExitCode(1);

    expect(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Failed);
});

it('runs a retried backup at the next minute, and never a cancelled one', function (): void {
    $this->seed(RoleSeeder::class);
    $this->backups = RunnableBackups::atFolders(1);
    $admin = User::factory()->create()->assignRole('administrator');
    $failed = JobsFixtures::job([
        'kind' => 'backup', 'label' => 'Back up now', 'starter' => 'person', 'started_by' => $admin->id,
        'run_type' => 'backup', 'run_id' => 'site', 'state' => 'failed', 'total' => 7, 'done' => 3,
        'message' => 'No place answered, so nothing was backed up.', 'ended_at' => now()->subHour(), 'created_at' => now()->subHour(),
    ]);
    $cancelled = (string) app(RequestBackup::class)->execute(new RequestBackupInput((string) $admin->id));
    app(CancelJob::class)->execute(new CancelJobInput($cancelled, (string) $admin->id));
    app(RetryJob::class)->execute(new RetryJobInput($failed, (string) $admin->id));

    $this->artisan('teme:backup:due')->expectsOutput('Back up now: done.')->assertExitCode(0);

    expect(TrackedJob::query()->findOrFail($failed)->state)->toBe(JobState::Finished)
        ->and(TrackedJob::query()->findOrFail($cancelled)->state)->toBe(JobState::Cancelled)
        ->and(BackupRun::query()->whereNull('parent_id')->where('kind', BackupRunKind::Backup->value)->count())->toBe(1);
});
