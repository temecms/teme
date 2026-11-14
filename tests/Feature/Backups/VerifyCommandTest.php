<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    VerifyDatabase::unnamed();
    $this->spare = null;
    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);
});

afterEach(function (): void {
    $this->spare?->drop();
    $this->backups->delete();
    OtherSession::close();
});

it('checks the backups now: reads back, then test-restores, printing each step', function (): void {
    $this->spare = VerifyDatabase::make();

    $this->artisan('teme:backup:verify')
        ->expectsOutput('Reading back part 1 of 7 of the backups at Place 1.')
        ->expectsOutput('Read-back: done.')
        ->expectsOutput("Emptying the spare database {$this->spare->name}.")
        ->expectsOutput('Comparing its rows with the manifest.')
        ->expectsOutput('Test restore: done.')
        ->assertExitCode(0);

    expect(BackupRun::query()->whereNull('parent_id')->where('started_by', 'command')->pluck('kind')->all())
        ->toContain(BackupRunKind::ReadBack, BackupRunKind::TestRestore);
});

it('says the test restore is skipped without a spare database, and fails only when that was all it was asked', function (): void {
    $skipped = 'Test restore skipped: no spare database is named. At the server, name one as TEME_VERIFY_DATABASE.';

    $this->artisan('teme:backup:verify')->expectsOutput('Read-back: done.')->expectsOutput($skipped)->assertExitCode(0);
    $this->artisan('teme:backup:verify --test-restore')->expectsOutput($skipped)->doesntExpectOutputToContain('Read-back')->assertExitCode(1);

    expect(BackupRun::query()->where('kind', BackupRunKind::TestRestore->value)->count())->toBe(0);
});

it('exits 1 with the words when a check fails, while another backup holds the lock, or before backups are set up', function (): void {
    Process::fake(['*read-data-subset*' => Process::result('', ScriptedRestic::exitError(1, 'Fatal: repository contains errors'), 1)]);

    $this->artisan('teme:backup:verify --read-back')
        ->expectsOutput('Read-back failed: restic found damage reading back part 1 of 7 of the backups at Place 1. Run restic check --read-data-subset=1/7 there to see it.')
        ->assertExitCode(1);

    OtherSession::holdBackupLock();
    $this->artisan('teme:backup:verify --read-back')->expectsOutput('A backup is already running. Try again when it has finished.')->assertExitCode(1);
    OtherSession::close();

    $this->backups->places[0]->keys()->delete();
    $this->artisan('teme:backup:verify --read-back')->expectsOutput('Finish setting backups up first.')->assertExitCode(1);
});
