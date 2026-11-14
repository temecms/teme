<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\EndBackupRun;
use App\Domain\Backups\Operations\Input\EndBackupRunInput;
use App\Domain\Backups\Operations\Input\StartBackupRunInput;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\PlaceOutcome;
use App\Domain\Backups\Tools\ResticResult;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\OtherSession;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    // Set up, as the keys-and-places part leaves it: a place in use with a recovery key recorded.
    $this->place = BackupRecords::destination();
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    // Under the backup lock, as BackupSite and PruneBackups start their runs.
    $this->start = fn (StartBackupRunInput $input): string => app(BackupLock::class)->during(fn (): string => app(StartBackupRun::class)->execute($input));
    $this->end = fn (EndBackupRunInput $input): mixed => app(EndBackupRun::class)->execute($input);
    $this->asOperator = fn () => app()->instance(Elevation::class, new OperatorElevation);
});

afterEach(function (): void {
    OtherSession::close();
});

it('says whether this process still holds the backup lock, and not once its session is gone', function (): void {
    $lock = app(BackupLock::class);

    expect($lock->stillMine())->toBeFalse()
        ->and($lock->during(fn (): bool => $lock->stillMine()))->toBeTrue()
        ->and($lock->during(function () use ($lock): bool {
            // The lock's session ends under the run, as a restarted server or a dropped connection ends it.
            DB::purge(BackupLock::CONNECTION);

            return $lock->stillMine();
        }))->toBeFalse()
        ->and($lock->stillMine())->toBeFalse();

    OtherSession::holdBackupLock();

    expect($lock->stillMine())->toBeFalse();
});

it('starts a whole backup or prune only under the backup lock, ending nothing and writing nothing without it', function (): void {
    ($this->asOperator)();
    $abandoned = BackupRecords::run(['state' => BackupRunState::Running, 'ended_at' => null]);

    expect(fn () => app(StartBackupRun::class)->execute(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Command)))
        ->toThrow(LogicException::class, 'A backup or a prune starts only under the backup lock.')
        ->and($abandoned->fresh()->state)->toBe(BackupRunState::Running)
        ->and(BackupRun::query()->count())->toBe(1);
});

it('records a run as running, then ends it with its figures, its snapshot, its place and its message', function (): void {
    ($this->asOperator)();
    $id = ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Command));
    $running = BackupRun::query()->findOrFail($id);

    expect($running->state)->toBe(BackupRunState::Running)
        ->and($running->started_by)->toBe(BackupTrigger::Command)
        ->and($running->actor_id)->toBeNull()
        ->and($running->ended_at)->toBeNull()
        ->and($running->figures)->toBe([]);

    ($this->end)(new EndBackupRunInput($id, false, str_repeat('x', 2100), ['seconds' => 4, 'rows' => 12], str_repeat('a', 64), $this->place->id));
    $ended = $running->fresh();

    expect($ended->state)->toBe(BackupRunState::Failed)
        ->and($ended->ended_at)->not->toBeNull()
        ->and($ended->figures)->toEqual(['seconds' => 4, 'rows' => 12])
        ->and($ended->snapshot_id)->toBe(str_repeat('a', 64))
        ->and($ended->destination_id)->toBe($this->place->id)
        ->and(mb_strlen((string) $ended->message))->toBe(2000)
        ->and(fn () => ($this->end)(new EndBackupRunInput($id, true)))
        ->toThrow(ValidationException::class, 'This run has already ended.')
        ->and($ended->fresh()->state)->toBe(BackupRunState::Failed);
});

it('starts a run for the operator and an administrator, and writes nothing when authorization fails', function (): void {
    $asAdmin = ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Person, actorId: (string) $this->admin->id));

    expect(BackupRun::query()->findOrFail($asAdmin)->actor_id)->toBe($this->admin->id)
        ->and(fn () => ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Person, actorId: (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        // A null actor is the operator only while OperatorElevation is bound.
        ->and(fn () => ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Schedule)))
        ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(BackupRun::query()->count())->toBe(1);

    ($this->asOperator)();

    expect(($this->start)(new StartBackupRunInput(BackupRunKind::Prune, BackupTrigger::Schedule)))->toBeString()
        ->and(BackupRun::query()->count())->toBe(2);
});

it('ends a run for the operator and an administrator, and writes nothing when authorization fails', function (): void {
    ($this->asOperator)();
    $id = ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Command));
    app()->forgetInstance(Elevation::class);

    expect(fn () => ($this->end)(new EndBackupRunInput($id, true, actorId: (string) $this->editor->id, places: [$this->place->id => PlaceOutcome::answered()])))
        ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(BackupRun::query()->findOrFail($id)->state)->toBe(BackupRunState::Running)
        ->and($this->place->fresh()->last_answered_at)->toBeNull();

    ($this->end)(new EndBackupRunInput($id, true, actorId: (string) $this->admin->id));

    expect(BackupRun::query()->findOrFail($id)->state)->toBe(BackupRunState::Finished);
});

it('refuses a whole backup before backups are set up, writing nothing', function (): void {
    ($this->asOperator)();
    BackupKey::query()->where('role', BackupKeyRole::Recovery->value)->delete();

    expect(fn () => ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Command)))
        ->toThrow(ValidationException::class, 'Finish setting backups up first.')
        ->and(BackupRun::query()->count())->toBe(0);
});

it('ends, as failed, every backup or prune left running by a process that ended, and their steps, when a new one starts', function (): void {
    $running = ['state' => BackupRunState::Running, 'ended_at' => null, 'started_at' => now()->subHours(3)];
    $deadBackup = BackupRecords::run($running);
    $deadCopy = BackupRecords::run([...$running, 'kind' => BackupRunKind::Copy, 'parent_id' => $deadBackup->id, 'destination_id' => $this->place->id]);
    $deadPrune = BackupRecords::run([...$running, 'kind' => BackupRunKind::Prune]);
    // Not a backup's or a prune's: verification's and the restore's own, which may be working.
    $readBack = BackupRecords::run([...$running, 'kind' => BackupRunKind::ReadBack]);
    $restore = BackupRecords::run([...$running, 'kind' => BackupRunKind::Restore]);
    $finished = BackupRecords::run();

    ($this->asOperator)();
    $new = ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Schedule));

    foreach ([$deadBackup, $deadCopy, $deadPrune] as $run) {
        expect($run->fresh()->state)->toBe(BackupRunState::Failed)
            ->and($run->fresh()->message)->toBe('This stopped before it finished: the server stopped running it.')
            ->and($run->fresh()->ended_at?->greaterThanOrEqualTo($run->started_at))->toBeTrue();
    }

    expect($readBack->fresh()->state)->toBe(BackupRunState::Running)
        ->and($restore->fresh()->state)->toBe(BackupRunState::Running)
        ->and($finished->fresh()->message)->toBeNull()
        ->and(BackupRun::query()->findOrFail($new)->state)->toBe(BackupRunState::Running);

    // A step of the new run ends nothing.
    $later = BackupRecords::run($running);
    ($this->start)(new StartBackupRunInput(BackupRunKind::Check, BackupTrigger::Schedule, $new, $this->place->id));

    expect($later->fresh()->state)->toBe(BackupRunState::Running);
});

it('sets each place a run reached for ready or failing, by what restic saw there', function (): void {
    $locked = BackupRecords::destination(['state' => BackupDestinationState::Failing, 'last_error' => 'Old words.', 'last_answered_at' => now()->subDay()]);
    $gone = BackupRecords::destination(['last_answered_at' => now()->subDays(2)]);
    $removed = BackupRecords::destination(['state' => BackupDestinationState::Removed, 'removed_at' => now()]);
    ($this->asOperator)();
    $id = ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Command));

    ($this->end)(new EndBackupRunInput($id, true, places: [
        $this->place->id => PlaceOutcome::answered(),
        $locked->id => PlaceOutcome::failed(ResticResult::ended(11, '', '', []), $locked->name),
        $gone->id => PlaceOutcome::failed(ResticResult::ended(10, '', '', []), $gone->name),
        $removed->id => PlaceOutcome::failed(ResticResult::ended(10, '', '', []), $removed->name),
    ]));

    expect($this->place->fresh()->state)->toBe(BackupDestinationState::Ready)
        ->and($this->place->fresh()->last_error)->toBeNull()
        ->and($this->place->fresh()->last_answered_at?->greaterThan(now()->subMinute()))->toBeTrue()
        // Locked: restic reached it, so it answered just now.
        ->and($locked->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($locked->fresh()->last_error)->toBe("The backups at {$locked->name} were locked by another task, so this didn’t run.")
        ->and($locked->fresh()->last_answered_at?->greaterThan(now()->subMinute()))->toBeTrue()
        // Gone: restic never reached it, so it last answered two days ago.
        ->and($gone->fresh()->last_error)->toBe("Teme found no backups at {$gone->name}. Check its address, and that the storage is still there.")
        ->and($gone->fresh()->last_answered_at?->lessThan(now()->subDay()))->toBeTrue()
        ->and($removed->fresh()->state)->toBe(BackupDestinationState::Removed)
        ->and($removed->fresh()->last_error)->toBeNull();
});

it('names the person on a person’s run, and only there', function (): void {
    ($this->asOperator)();

    expect(fn () => ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Person)))
        ->toThrow(InvalidArgumentException::class, 'A person’s run names the person, and only a person’s run does.')
        ->and(fn () => ($this->start)(new StartBackupRunInput(BackupRunKind::Backup, BackupTrigger::Command, actorId: (string) $this->admin->id)))
        ->toThrow(InvalidArgumentException::class, 'A person’s run names the person, and only a person’s run does.')
        ->and(fn () => ($this->start)(new StartBackupRunInput(BackupRunKind::Copy, BackupTrigger::Command, '01jnosuchrun00000000000000')))
        ->toThrow(InvalidArgumentException::class, 'A step belongs to a whole run that exists.')
        ->and(BackupRun::query()->count())->toBe(0);
});
