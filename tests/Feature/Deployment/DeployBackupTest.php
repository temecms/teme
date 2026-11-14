<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Deployment\Health\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Deployment\DeployLockSession;
use Tests\Fixtures\Deployment\DeployRun;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeployRun::isolatePageCache();
    $this->site = ScratchSite::open();
    // An account exists, so a deploy prints no first-run lines (issue 246).
    User::factory()->create();
    $this->backups = null;
    app()->instance(Doctor::class, new Doctor([]));
    app('migrator')->path(DeployRun::migrations('probe'));
});

afterEach(function (): void {
    OtherSession::close();
    DeployLockSession::close();
    $this->backups?->delete();
    $this->site->close();
});

/**
 * The deploy's whole backup run: one, with no parent.
 */
function dbBackupRun(): BackupRun
{
    return BackupRun::query()->where('kind', BackupRunKind::Backup->value)->whereNull('parent_id')->sole();
}

it('backs up before it migrates, as the deploy, and says where (I-361)', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    $run = DeployRun::here();
    $lines = ['Backing up before migrating.', 'Backed up to Place 1.', 'Migrating.', 'Deployed: 1 migration ran.'];

    expect($run->status)->toBe(0)
        ->and($run->inOrder($lines))->toBe($lines)
        ->and(dbBackupRun()->started_by)->toBe(BackupTrigger::Deploy)
        ->and(dbBackupRun()->state)->toBe(BackupRunState::Finished)
        ->and(Schema::hasTable('deploy_probe'))->toBeTrue();
});

it('stops before migrating when the backup fails, changing nothing', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    // The place's repository is gone, so the place no longer answers.
    $this->backups->repositories[0]->delete();

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(3)
        ->and($run->json['outcome'])->toBe('backup-failed')
        ->and($run->json['backup']['state'])->toBe('failed')
        ->and($run->json['backup']['sentence'])->toBeString()->not->toBe('')
        ->and($run->json['sentence'])->toBe('Nothing was changed: the backup must succeed before Teme migrates. Fix what it says, then run php artisan teme:deploy again.')
        ->and($run->json['steps'])->toMatchArray(['backup' => 'failed', 'migrate' => 'not-run'])
        ->and(dbBackupRun()->state)->toBe(BackupRunState::Failed)
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse();
});

it('stops in the restore\'s words while a restored site\'s places are paused', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->backups->places[0]->forceFill(['paused_at' => now()])->save();

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(3)
        ->and($run->json['backup'])->toMatchArray(['state' => 'failed', 'run' => null, 'sentence' => BackupPlaces::PAUSED])
        ->and(BackupRun::query()->count())->toBe(0)
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse();
});

it('waits for a backup that is running, and gives up after --wait seconds, changing nothing', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $this->backups = RunnableBackups::atFolders(1);
    OtherSession::holdBackupLock();

    $run = DeployRun::here(['--wait' => 25]);
    $lines = [
        'Backing up before migrating.',
        'A backup is already running. Waiting for it to finish, then backing up.',
        'A backup still held the backup lock after 25 seconds, so nothing was changed. Run php artisan teme:deploy again once it has finished.',
    ];

    expect($run->status)->toBe(7)
        ->and($run->inOrder($lines))->toBe($lines)
        ->and(BackupRun::query()->count())->toBe(0)
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse();
    Sleep::assertSleptTimes(3);
});

it('backs up once the running backup has finished, then migrates', function (): void {
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function (): void {
        OtherSession::close();
    });
    $this->backups = RunnableBackups::atFolders(1);
    OtherSession::holdBackupLock();

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(0)
        ->and($run->json['backup'])->toBe(['state' => 'done', 'run' => dbBackupRun()->id, 'place' => 'Place 1', 'sentence' => null, 'problems' => []])
        ->and($run->json['migrations']['ran'])->toBe([DeployRun::PROBE]);
    Sleep::assertSleptTimes(1);
});

it('stops before migrating when it has lost its lock, migrating nothing', function (): void {
    Sleep::fake(syncWithCarbon: true);
    // While the deploy waits for the backup lock: the backup ends, then the deploy's own lock session is lost.
    Sleep::whenFakingSleep(function (): void {
        OtherSession::close();
        DeployLockSession::endLockHolders();
    });
    $this->backups = RunnableBackups::atFolders(1);
    OtherSession::holdBackupLock();

    $run = DeployRun::here(['--json' => true]);

    expect($run->status)->toBe(1)
        ->and($run->json['outcome'])->toBe('failed')
        ->and($run->json['sentence'])->toBe('The deploy lost its lock before migrating, so nothing was migrated: another deploy may have started. Run php artisan teme:deploy again.')
        ->and($run->json['steps'])->toMatchArray(['backup' => 'done', 'migrate' => 'not-run'])
        ->and(Schema::hasTable('deploy_probe'))->toBeFalse();
});

it('names the backup to restore when a migration fails after it', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app('migrator')->path(DeployRun::migrations('failing'));

    $run = DeployRun::here(['--json' => true]);
    $backup = dbBackupRun()->id;

    expect($run->status)->toBe(4)
        ->and($run->json['outcome'])->toBe('migration-failed')
        ->and($run->json['migrations']['ran'])->toBe([DeployRun::PROBE])
        ->and($run->json['migrations']['error'])->toContain('This migration fails on purpose.')
        ->and($run->json['sentence'])->toBe("The migrations before it have run and the rest haven't. Fix what stopped it, then run php artisan teme:deploy again, or restore the backup taken just now with php artisan teme:backup:restore {$backup}.")
        ->and($run->json['steps'])->toMatchArray(['backup' => 'done', 'migrate' => 'failed', 'queue_restart' => 'not-run'])
        ->and(Schema::hasTable('deploy_probe'))->toBeTrue()
        ->and(Schema::hasTable('deploy_failed'))->toBeFalse();
});
