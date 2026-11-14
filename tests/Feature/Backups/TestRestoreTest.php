<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\Backups\AlteredManifest;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\VerifyDatabase;
use Tests\Fixtures\Backups\WatchedProcesses;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->media = new CommittedMedia;
    $this->backups = RunnableBackups::atFolders(1);
    // Two originals the backup's moment sees: an image's and a document's.
    $this->media->image();
    $this->media->document();
    $this->spare = VerifyDatabase::make();
    $this->backup = app(BackupSite::class)->run(BackupTrigger::Command);
    $this->run = $this->backups->runsAt(0)[0];
    $this->repository = $this->backups->repositories[0]->repository;
    $this->test = fn () => app(VerifyBackups::class)->testRestore(BackupTrigger::Command);
});

afterEach(function (): void {
    $this->spare->drop();
    $this->backups->delete();
    $this->media->delete();
    OtherSession::close();
});

it('restores the newest backup into the spare database, finds its rows as the manifest says, reads back its media, and empties it after', function (): void {
    $watched = WatchedProcesses::runForReal();

    $result = ($this->test)();
    $row = BackupRun::query()->findOrFail($result->runId);
    $fetched = $watched->restic('dump --target');
    $targets = array_map(fn (array $seen): string => $seen['command'][array_search('--target', $seen['command'], true) + 1], $fetched);

    expect($this->backup->succeeded)->toBeTrue((string) $this->backup->sentence)
        ->and($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($row->kind)->toBe(BackupRunKind::TestRestore)
        ->and($row->state)->toBe(BackupRunState::Finished)
        ->and($row->parent_id)->toBeNull()
        ->and($row->destination_id)->toBe($this->backups->places[0]->id)
        ->and($row->snapshot_id)->toBe($this->run->parts[BackupSnapshots::MANIFEST])
        ->and(array_keys($row->figures))->toEqualCanonicalizing(['backup_run', 'tables', 'rows', 'differences', 'media_files', 'media_wrong', 'seconds'])
        ->and($row->figures['backup_run'])->toBe($this->backup->runId)
        ->and($row->figures['differences'])->toBe(0)
        ->and($row->figures['tables'])->toBeGreaterThan(10)
        ->and($row->figures['media_files'])->toBe(2)
        ->and($row->figures['media_wrong'])->toBe(0)
        // The dump, then each of the two originals.
        ->and($fetched)->toHaveCount(3)
        ->and(array_values(array_filter($targets, is_file(...))))->toBe([])
        ->and(is_dir(dirname($targets[0])))->toBeFalse()
        ->and($this->spare->tables())->toBe([])
        ->and($this->spare->extensions())->toBe(['plpgsql']);
});

it('fails, naming the table, when the restored rows differ from the manifest', function (): void {
    AlteredManifest::replace($this->repository, $this->run, function (array $manifest): array {
        $manifest['database']['tables']['media']++;

        return $manifest;
    });

    $result = ($this->test)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toStartWith('The test restore of the backup of ')
        ->toEndWith('didn’t match its manifest: media has 2 rows in the restored copy, and 3 in the manifest.')
        ->and(BackupRun::query()->findOrFail($result->runId)->figures['differences'])->toBe(1)
        ->and($this->spare->tables())->toBe([]);
});

it('fails when a media file read back differs from the manifest’s SHA-256', function (): void {
    $label = '';
    AlteredManifest::replace($this->repository, $this->run, function (array $manifest) use (&$label): array {
        $manifest['media']['originals'][0]['sha256'] = str_repeat('0', 64);
        $label = $manifest['media']['originals'][0]['disk'].':'.$manifest['media']['originals'][0]['path'];

        return $manifest;
    });

    $result = ($this->test)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe("1 of the 2 media files read back from Place 1 didn’t match the manifest: {$label}.")
        ->and(BackupRun::query()->findOrFail($result->runId)->figures['media_wrong'])->toBe(1);
});

it('reads back at most 50 media files, chosen at random from the manifest’s originals', function (): void {
    $many = array_map(fn (int $n): array => ['path' => "file-{$n}"], range(1, 60));
    $sample = VerifyBackups::sample($many, VerifyBackups::SAMPLE);

    expect($sample)->toHaveCount(50)
        ->and(array_unique(array_column($sample, 'path')))->toHaveCount(50)
        ->and(array_diff(array_column($sample, 'path'), array_column($many, 'path')))->toBe([])
        ->and(VerifyBackups::sample(array_slice($many, 0, 3), VerifyBackups::SAMPLE))->toBe(array_slice($many, 0, 3));
});

it('fails when the copy won’t restore, keeps pg_restore’s words for the log, and leaves the spare database empty', function (): void {
    // Only pg_restore fails; restic runs for real (I-2400).
    Process::fake(['*pg_restore*' => Process::result('', 'pg_restore: error: could not execute query: something broke', 1)]);
    Log::spy();

    $result = ($this->test)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe('pg_restore couldn’t restore the copy into the spare database. The log has its own words.')
        ->and($this->spare->tables())->toBe([])
        ->and($this->backups->places[0]->fresh()->state)->toBe(BackupDestinationState::Ready);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $line): bool => str_contains($line, 'something broke'))->once();
});

it('restores as a role that isn’t a superuser and owns the spare database, as on a server (I-2406)', function (): void {
    $this->spare->drop();
    $this->spare = VerifyDatabase::make(asRestrictedRole: true);

    $result = ($this->test)();

    expect($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($this->spare->tables())->toBe([]);
});

it('fails, fetching nothing, when no place holds the newest backup whole', function (): void {
    app(Restic::class)->run($this->repository, ['forget', $this->run->parts[BackupSnapshots::MEDIA]]);
    $watched = WatchedProcesses::runForReal();

    $result = ($this->test)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toStartWith('The newest backup, of ')->toEndWith(', isn’t whole at any place backups are kept.')
        ->and($watched->restic('dump'))->toBe([]);
});

it('fails before fetching the copy when there isn’t room for it', function (): void {
    $row = BackupRun::query()->findOrFail($this->backup->runId);
    $row->figures = [...$row->figures, 'database_bytes' => PHP_INT_MAX];
    $row->save();
    $watched = WatchedProcesses::runForReal();

    $result = ($this->test)();

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toStartWith('There isn’t room for the copy of the database: it needs ')
        ->and($watched->restic('dump --target'))->toBe([]);
});
