<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\Queries\DueVerification;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    // Two places, the second holding the backup by restic's copy.
    $this->backups = RunnableBackups::atFolders(2);
    app(BackupSite::class)->run(BackupTrigger::Command);
    $this->verify = fn (?string $actorId = null, BackupTrigger $by = BackupTrigger::Command) => app(VerifyBackups::class)->readBack($by, $actorId);
});

afterEach(function (): void {
    $this->backups->delete();
    OtherSession::close();
});

it('reads back a seventh of each place’s data, as a run with a step at each place', function (): void {
    $watched = WatchedProcesses::runForReal();

    $result = ($this->verify)();
    $whole = BackupRun::query()->findOrFail($result->runId);
    $steps = BackupRun::query()->where('parent_id', $result->runId)->orderBy('started_at')->get();

    expect($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($whole->kind)->toBe(BackupRunKind::ReadBack)
        ->and($whole->state)->toBe(BackupRunState::Finished)
        ->and($whole->destination_id)->toBeNull()
        ->and($whole->figures['places'])->toBe(2)
        ->and($steps)->toHaveCount(2)
        ->and($steps->pluck('destination_id')->all())->toBe([$this->backups->places[0]->id, $this->backups->places[1]->id])
        ->and($steps->every(fn (BackupRun $step): bool => $step->kind === BackupRunKind::ReadBack && $step->state === BackupRunState::Finished && $step->figures['subset'] === 1))->toBeTrue()
        ->and($watched->restic('check --read-data-subset=1/7'))->toHaveCount(2);
});

it('reads the next seventh at each place each night, and a seventh that failed again', function (): void {
    $place = $this->backups->places[0];
    $whole = BackupRecords::run(['kind' => BackupRunKind::ReadBack]);
    $step = fn (int $subset, BackupRunState $state, int $minutesAgo): BackupRun => BackupRecords::run([
        'kind' => BackupRunKind::ReadBack,
        'parent_id' => $whole->id,
        'destination_id' => $place->id,
        'state' => $state,
        'figures' => ['seconds' => 1, 'subset' => $subset],
        'started_at' => now()->subMinutes($minutesAgo),
        'ended_at' => now()->subMinutes($minutesAgo),
    ]);
    $due = app(DueVerification::class);

    expect($due->nextSeventh($place->id))->toBe(1);

    $step(3, BackupRunState::Finished, 30);

    expect($due->nextSeventh($place->id))->toBe(4);

    $step(4, BackupRunState::Failed, 20);

    expect($due->nextSeventh($place->id))->toBe(4)
        ->and($due->nextSeventh($this->backups->places[1]->id))->toBe(1);

    $step(7, BackupRunState::Finished, 10);

    expect($due->nextSeventh($place->id))->toBe(1);
});

it('fails the read-back in words when restic finds damage, and the place turns failing', function (): void {
    // Only the read-back answers as damaged; every other call runs for real (I-2400).
    Process::fake(['*read-data-subset*' => Process::result('', ScriptedRestic::exitError(1, 'Fatal: repository contains errors'), 1)]);
    $damage = 'restic found damage reading back part 1 of 7 of the backups at Place 1. Run restic check --read-data-subset=1/7 there to see it.';

    $result = ($this->verify)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe($damage.' '.str_replace('Place 1', 'Place 2', $damage))
        ->and(BackupRun::query()->findOrFail($result->runId)->state)->toBe(BackupRunState::Failed)
        ->and($this->backups->places[0]->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($this->backups->places[0]->fresh()->last_error)->toBe($damage);
});

it('refuses a place that holds other backups than Teme’s, and reads back the next', function (): void {
    $this->backups->places[0]->forceFill(['repository_id' => str_repeat('a', 64)])->save();

    $result = ($this->verify)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe('Place 1 holds other backups than the ones Teme made there.')
        ->and(BackupRun::query()->where('parent_id', $result->runId)->where('destination_id', $this->backups->places[1]->id)->sole()->state)->toBe(BackupRunState::Finished);
});

it('ends, as failed, a read-back or test restore a process left running, and no other part’s run', function (): void {
    $readBack = BackupRecords::run(['kind' => BackupRunKind::ReadBack, 'state' => BackupRunState::Running, 'ended_at' => null]);
    $step = BackupRecords::run(['kind' => BackupRunKind::ReadBack, 'parent_id' => $readBack->id, 'destination_id' => $this->backups->places[0]->id, 'state' => BackupRunState::Running, 'ended_at' => null]);
    $testRestore = BackupRecords::run(['kind' => BackupRunKind::TestRestore, 'state' => BackupRunState::Running, 'ended_at' => null]);
    $restore = BackupRecords::run(['kind' => BackupRunKind::Restore, 'started_by' => BackupTrigger::Command, 'state' => BackupRunState::Running, 'ended_at' => null]);

    ($this->verify)();

    foreach ([$readBack, $step, $testRestore] as $left) {
        expect($left->fresh()->state)->toBe(BackupRunState::Failed)
            ->and($left->fresh()->message)->toBe(StartBackupRun::ABANDONED);
    }

    expect($restore->fresh()->state)->toBe(BackupRunState::Running);
});

it('writes nothing when authorization fails', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = BackupRun::query()->count();

    expect(fn () => ($this->verify)((string) $editor->id, BackupTrigger::Person))->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(BackupRun::query()->count())->toBe($before);
});

it('refuses, writing nothing, while another backup holds the lock, or before backups are set up', function (): void {
    $before = BackupRun::query()->count();
    OtherSession::holdBackupLock();

    expect(fn () => ($this->verify)())->toThrow(BackupAlreadyRunning::class);

    OtherSession::close();
    $this->backups->places[0]->keys()->delete();

    expect(fn () => ($this->verify)())->toThrow(ValidationException::class, 'Finish setting backups up first.')
        ->and(BackupRun::query()->count())->toBe($before);
});
