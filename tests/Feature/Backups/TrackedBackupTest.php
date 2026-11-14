<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupResult;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Tracking\TrackedBackup;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Jobs\JobsFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // The scheduler's process: the operator is present.
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->backups = null;
    $this->steps = [];
    $this->job = fn (array $changes = []): string => JobsFixtures::job([
        'kind' => 'backup',
        'label' => 'Back up now',
        'starter' => 'person',
        'started_by' => $this->admin->id,
        'run_type' => 'backup',
        'run_id' => 'site',
        'state' => 'waiting',
        'total' => null,
        'done' => 0,
        'message' => null,
        ...$changes,
    ]);
    $this->run = fn (string $jobId): ?BackupResult => app(TrackedBackup::class)->run($jobId, function (string $step): void {
        $this->steps[] = $step;
    });
});

afterEach(function (): void {
    $this->backups?->delete();
    OtherSession::close();
});

it('runs a waiting Back up now as the person who asked, and finishes its job in words', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = ($this->job)();

    $result = ($this->run)($id);
    $job = TrackedJob::query()->findOrFail($id);
    $run = BackupRun::query()->findOrFail($result->runId);

    expect($result->allWell())->toBeTrue((string) $result->sentence)
        ->and($run->started_by)->toBe(BackupTrigger::Person)
        ->and($run->actor_id)->toBe($this->admin->id)
        ->and($job->state)->toBe(JobState::Finished)
        ->and($job->message)->toBe('The database is backed up at Place 1, and checked.')
        ->and([$job->done, $job->total])->toBe([BackupSite::steps(1), BackupSite::steps(1)]);
});

it('reports each step as it starts, as many as BackupSite::steps() counts, for one place and for two', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    $id = ($this->job)();

    ($this->run)($id);

    expect(BackupSite::steps(1))->toBe(7)
        ->and(BackupSite::steps(2))->toBe(9)
        ->and($this->steps)->toHaveCount(BackupSite::steps(2))
        ->and($this->steps[0])->toBe('Checking restic and PostgreSQL’s tools.')
        ->and(array_slice($this->steps, -2))->toBe(['Copying the backups to Place 2.', 'Checking the backup at Place 2.'])
        ->and(TrackedJob::query()->findOrFail($id)->message)->toBe('The database is backed up at Place 1 and copied to Place 2, and checked.');
});

it('says what a finished backup holds and where, with its singular', function (int $files, array $others, string $words): void {
    expect(TrackedBackup::finishedWords(new BackupResult('run', true, null, [], 'This server', $files), $others))->toBe($words);
})->with([
    'no media' => [0, [], 'The database is backed up at This server, and checked.'],
    'one file' => [1, [], 'The database and 1 media file are backed up at This server, and checked.'],
    'many, copied twice' => [1204, ['Off-site', 'Archive'], 'The database and 1,204 media files are backed up at This server and copied to Off-site and Archive, and checked.'],
]);

it('fails its job in the run’s own words when the backup fails', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->backups->repositories[0]->delete();
    $id = ($this->job)();

    $result = ($this->run)($id);
    $job = TrackedJob::query()->findOrFail($id);

    expect($result->succeeded)->toBeFalse()
        ->and($job->state)->toBe(JobState::Failed)
        ->and($job->message)->toBe('No place answered, so nothing was backed up. Teme found no backups at Place 1. Check its address, and that the storage is still there.');
});

it('fails its job, writing no run, when backups are paused, or the person who asked no longer may, or no longer has an account', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    BackupDestination::query()->update(['paused_at' => now()]);
    $paused = ($this->job)();
    expect(($this->run)($paused))->toBeNull();
    BackupDestination::query()->update(['paused_at' => null]);

    // The scheduler holds the operator's elevation; the backup is still authorized as the person.
    $this->admin->syncRoles(['editor']);
    $demoted = ($this->job)();
    expect(($this->run)($demoted))->toBeNull();

    $gone = ($this->job)(['started_by' => null]);
    expect(($this->run)($gone))->toBeNull();

    expect(TrackedJob::query()->whereKey([$paused, $demoted, $gone])->pluck('message', 'id')->all())->toEqual([
        $paused => BackupPlaces::PAUSED,
        $demoted => 'The person who asked for this backup no longer manages backups, so it didn’t run.',
        $gone => 'The person who asked for this backup no longer has an account, so it didn’t run.',
    ])
        ->and(TrackedJob::query()->where('state', JobState::Failed->value)->count())->toBe(3)
        ->and(BackupRun::query()->count())->toBe(0)
        ->and($this->backups->runsAt(0))->toBe([]);
});

it('fails its job in the unexpected error’s words, reported as the operator, when the person who asked is demoted or deleted mid-run', function (string $how): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = ($this->job)();

    $run = fn () => app(TrackedBackup::class)->run($id, function (string $step) use ($how): void {
        if ($step !== 'Writing the manifest.') {
            return;
        }

        if ($how === 'demoted') {
            $this->admin->syncRoles(['editor']);
        } else {
            DB::table('users')->where('id', $this->admin->id)->delete();
        }
    });

    // EndBackupRun is authorized as the person, and refuses; the error goes on to the scheduler's log.
    expect($run)->toThrow(AuthorizationException::class, 'Only an administrator manages backups.');

    $job = TrackedJob::query()->findOrFail($id);

    expect($job->state)->toBe(JobState::Failed)
        ->and($job->message)->toBe(TrackedBackup::UNEXPECTED);
})->with(['demoted', 'deleted']);

it('leaves a job that no longer waits as it is, writing nothing', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = ($this->job)(['state' => 'cancelled', 'message' => 'Cancelled by Ada Admin.', 'ended_at' => now()]);

    expect(($this->run)($id))->toBeNull()
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled)
        ->and(BackupRun::query()->count())->toBe(0);
});

it('leaves its job waiting, and writes nothing, while another process holds the backup lock', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    OtherSession::holdBackupLock();
    $id = ($this->job)();

    expect(fn () => ($this->run)($id))->toThrow(BackupAlreadyRunning::class)
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Waiting)
        ->and(BackupRun::query()->count())->toBe(0);
});

it('runs only where the operator is present, never from a request or a queue worker', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app()->instance(Elevation::class, new DeniedElevation);
    $id = ($this->job)();

    expect(fn () => ($this->run)($id))->toThrow(LogicException::class, 'Backups are run by the scheduler or a teme: command, where the operator is present.')
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Waiting)
        ->and(BackupRun::query()->count())->toBe(0);
});

it('stops between steps when its job is cancelled before the backup is recorded, and the run fails in the cancel’s words', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = ($this->job)();

    $result = app(TrackedBackup::class)->run($id, function (string $step) use ($id): void {
        if ($step === 'Copying the database to Place 1.') {
            app(CancelJob::class)->execute(new CancelJobInput($id, (string) $this->admin->id));
        }
    });
    $run = BackupRun::query()->findOrFail($result->runId);
    $job = TrackedJob::query()->findOrFail($id);

    expect($result->succeeded)->toBeFalse()
        ->and($run->state)->toBe(BackupRunState::Failed)
        ->and($run->message)->toBe('Cancelled by Ada Admin before it finished.')
        ->and($job->state)->toBe(JobState::Cancelled)
        ->and($job->message)->toBe('Cancelled by Ada Admin.')
        ->and(BackupRun::query()->where('kind', BackupRunKind::Check->value)->count())->toBe(0);
});

it('skips the copies and checks left when cancelled after the backup is recorded, and keeps the backup', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    $id = ($this->job)();

    $result = app(TrackedBackup::class)->run($id, function (string $step) use ($id): void {
        if ($step === 'Checking the backup at Place 1.') {
            app(CancelJob::class)->execute(new CancelJobInput($id, (string) $this->admin->id));
        }
    });

    expect($result->succeeded)->toBeTrue()
        ->and($result->problems)->toBe(['Cancelled by Ada Admin before it finished.'])
        ->and(BackupRun::query()->findOrFail($result->runId)->state)->toBe(BackupRunState::Finished)
        ->and(BackupRun::query()->where('kind', BackupRunKind::Copy->value)->count())->toBe(0)
        ->and(BackupRun::query()->where('kind', BackupRunKind::Check->value)->count())->toBe(1)
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Cancelled);
});

it('ends, at its first step, a backup job a process that died left running', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $stale = ($this->job)(['state' => 'running', 'total' => 7, 'done' => 4, 'message' => 'Copying the database to Place 1.', 'created_at' => now()->subDay()]);
    $id = ($this->job)();

    ($this->run)($id);
    $left = TrackedJob::query()->findOrFail($stale);

    expect($left->state)->toBe(JobState::Failed)
        ->and($left->message)->toBe(StartBackupRun::ABANDONED)
        ->and(TrackedJob::query()->findOrFail($id)->state)->toBe(JobState::Finished);
});

it('carries a retried job’s count on from where it stopped', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = ($this->job)(['total' => 7, 'done' => 3, 'message' => 'Retrying from where it stopped.']);

    ($this->run)($id);
    $job = TrackedJob::query()->findOrFail($id);

    expect([$job->done, $job->total])->toBe([3 + BackupSite::steps(1), 3 + BackupSite::steps(1)]);
});
