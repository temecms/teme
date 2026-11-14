<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->backups = null;
});

afterEach(function (): void {
    $this->backups?->delete();
    OtherSession::close();
});

it('backs the site up as the operator, saying each step, and exits 0', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    $this->artisan('teme:backup:run')
        ->expectsOutput('Checking restic and PostgreSQL’s tools.')
        ->expectsOutput('Asking Place 1 to answer.')
        ->expectsOutput('Copying the database to Place 1.')
        ->expectsOutput('Backing up 0 media files to Place 1.')
        ->expectsOutput('Keeping the site’s key with the backup.')
        ->expectsOutput('Writing the manifest.')
        ->expectsOutput('Backed up to Place 1: the database and 0 media files.')
        ->assertExitCode(0);

    $run = BackupRun::query()->whereNull('parent_id')->sole();

    expect($run->started_by)->toBe(BackupTrigger::Command)
        ->and($run->actor_id)->toBeNull();
});

it('refuses, exit 1, before backups are set up or while another backup runs, writing nothing', function (): void {
    $this->backups = RunnableBackups::atFolders(1, setUp: false);

    $this->artisan('teme:backup:run')
        ->expectsOutput('Finish setting backups up first.')
        ->assertExitCode(1);

    BackupRecords::key($this->backups->places[0], ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    OtherSession::holdBackupLock();

    $this->artisan('teme:backup:run')
        ->expectsOutput('A backup is already running. Try again when it has finished.')
        ->assertExitCode(1);

    expect(BackupRun::query()->count())->toBe(0);
});

it('says why, exit 1, when the backup fails', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->backups->repositories[0]->delete();

    $this->artisan('teme:backup:run')
        ->expectsOutput('No place answered, so nothing was backed up. Teme found no backups at Place 1. Check its address, and that the storage is still there.')
        ->assertExitCode(1);
});
