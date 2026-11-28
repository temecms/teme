<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Verify\TemporaryDatabase;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    // Host-run (TEME_BACKUPS=host): no spare database named; the restore account makes a temporary one.
    VerifyDatabase::unnamed();
    config(['teme.backup_mode' => 'host']);
    config(['database.connections.'.TemporaryDatabase::CONNECTION => [...DB::connection()->getConfig(), 'database' => 'postgres']]);
    DB::purge(TemporaryDatabase::CONNECTION);

    $this->media = new CommittedMedia;
    $this->backups = RunnableBackups::atFolders(1);
    $this->media->image();
    $this->media->document();
    $this->backup = app(BackupSite::class)->run(BackupTrigger::Command);
});

afterEach(function (): void {
    $this->backups->delete();
    $this->media->delete();
    OtherSession::close();
    DB::purge(TemporaryDatabase::CONNECTION);
});

it('restores the newest backup into a temporary database under host, and drops it after', function (): void {
    $result = app(VerifyBackups::class)->testRestore(BackupTrigger::Command);
    $row = BackupRun::query()->findOrFail($result->runId);
    $leftovers = DB::connection(TemporaryDatabase::CONNECTION)->select("SELECT datname FROM pg_catalog.pg_database WHERE datname LIKE 'teme\\_restore\\_%'");

    expect($this->backup->succeeded)->toBeTrue((string) $this->backup->sentence)
        ->and($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($row->kind)->toBe(BackupRunKind::TestRestore)
        ->and($row->state)->toBe(BackupRunState::Finished)
        ->and($row->figures['differences'])->toBe(0)
        ->and($leftovers)->toBe([]);
});

it('sweeps a leftover temporary database before the test restore, so a dead run cannot leave a second copy behind', function (): void {
    $leftover = TemporaryDatabase::create();

    app(VerifyBackups::class)->testRestore(BackupTrigger::Command);

    expect(DB::connection(TemporaryDatabase::CONNECTION)->selectOne('SELECT 1 AS present FROM pg_database WHERE datname = ?', [$leftover]))->toBeNull();
});
