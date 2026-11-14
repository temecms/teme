<?php

declare(strict_types=1);

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\PostgresProgram;
use App\Domain\Backups\Tools\PostgresTools;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Media\MediaDisks;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    // Keys of the test's own, so "never in a message" can't pass on an empty one.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'app.previous_keys' => ['base64:'.base64_encode(str_repeat('p', 32))]]);
    $this->media = new CommittedMedia;
    $this->backups = null;
    $this->files = [];
    $this->dump = function (int $at, string $snapshot, string $file): mixed {
        return app(Restic::class)->run($this->backups->repositories[$at]->repository, ['dump', $snapshot, $file])->json();
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->media->delete();
    OtherSession::close();

    foreach ($this->files as $file) {
        @unlink($file);
    }
});

it('backs the site up at the first place: one run of four parts, the dump readable by pg_restore, the media in it', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $image = $this->media->image();

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $runs = $this->backups->runsAt(0);

    expect($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($result->place)->toBe('Place 1')
        ->and($result->mediaFiles)->toBe(3)
        ->and($runs)->toHaveCount(1)
        ->and($runs[0]->runId)->toBe($result->runId)
        ->and($runs[0]->complete())->toBeTrue();

    $repository = $this->backups->repositories[0]->repository;
    $file = $this->files[] = TemporaryRepository::freshFolder().'.dump';
    $read = app(Restic::class)->run($repository, ['dump', '--target', $file, $runs[0]->parts[BackupSnapshots::DATABASE], BackupSnapshots::DUMP_FILE]);
    $rows = app(PostgresTools::class)->run(PostgresProgram::Restore, ['--data-only', '--table=media', '--file=-', $file]);
    $listed = app(Restic::class)->run($repository, ['ls', $runs[0]->parts[BackupSnapshots::MEDIA]]);
    $paths = array_column($listed->messages(), 'path');

    expect($read->succeeded())->toBeTrue($read->detail())
        ->and($rows->output())->toContain($image)
        ->and($paths)->toContain(BackupSnapshots::pathInSnapshot(MediaDisks::originals()->path("{$image}/1/original.jpg")))
        ->and($paths)->toContain(BackupSnapshots::pathInSnapshot(MediaDisks::public()->path("{$image}/1/w640.webp")))
        ->and(($this->dump)(0, $runs[0]->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE)['run'] ?? null)->toBe($result->runId);
});

it('records the run: finished, its figures, its manifest’s snapshot, and its place ready', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->media->image(['w640', 'thumb']);

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $row = BackupRun::query()->findOrFail($result->runId);
    $place = $this->backups->places[0]->fresh();

    expect($row->state)->toBe(BackupRunState::Finished)
        ->and($row->started_by)->toBe(BackupTrigger::Command)
        ->and($row->parent_id)->toBeNull()
        ->and($row->destination_id)->toBe($place->id)
        ->and($row->snapshot_id)->toBe($this->backups->runsAt(0)[0]->parts[BackupSnapshots::MANIFEST])
        ->and($row->message)->toBeNull()
        ->and(array_keys($row->figures))->toEqualCanonicalizing(['seconds', 'database_bytes', 'media_files', 'media_bytes', 'missing_files', 'bytes_added', 'tables', 'rows'])
        ->and($row->figures['media_files'])->toBe(3)
        ->and($row->figures['missing_files'])->toBe(0)
        ->and($row->figures['database_bytes'])->toBeGreaterThan(0)
        ->and($row->figures['bytes_added'])->toBeGreaterThan(0)
        ->and($row->figures['tables'])->toBeGreaterThan(10)
        ->and($place->state)->toBe(BackupDestinationState::Ready)
        ->and($place->last_answered_at)->not->toBeNull();
});

it('keeps the APP_KEY inside the repository only', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $run = $this->backups->runsAt(0)[0];
    $appKey = ($this->dump)(0, $run->parts[BackupSnapshots::APP_KEY], BackupSnapshots::APP_KEY_FILE);
    $manifest = ($this->dump)(0, $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE);
    $recorded = json_encode(BackupRun::query()->get()->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($appKey)->toBe(['key' => config('app.key'), 'previous_keys' => config('app.previous_keys')])
        ->and($manifest['app_key'] ?? null)->toBe('app-key.json')
        ->and(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->not->toContain(substr((string) config('app.key'), 7))
        ->and($recorded)->not->toContain(substr((string) config('app.key'), 7))
        ->and($recorded)->not->toContain(TemporaryRepository::KEY);
});

it('backs up what the media rows name, and fails the run, naming the hole, when a file isn’t on this server', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->media->image();
    $hole = $this->media->image(['w640'], withFiles: false);

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $row = BackupRun::query()->findOrFail($result->runId);

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe("2 media files the database names weren’t on this server, so no backup holds them, such as originals:{$hole}/1/original.jpg.")
        ->and($row->state)->toBe(BackupRunState::Failed)
        ->and($row->message)->toBe($result->sentence)
        ->and($row->figures['missing_files'])->toBe(2)
        // Said as values, never by its sentence: a restore's safety backup goes on with this hole alone.
        ->and($result->missingFiles)->toBe(2)
        ->and($result->onlyMissingFiles())->toBeTrue()
        // Every part is written all the same: the best copy there is.
        ->and($this->backups->runsAt(0)[0]->complete())->toBeTrue()
        ->and($this->backups->places[0]->fresh()->state)->toBe(BackupDestinationState::Ready);
});

it('goes on to the next place when the first doesn’t answer, and marks the first failing', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    [$first, $second] = $this->backups->places;
    $this->backups->repositories[0]->delete();

    $result = app(BackupSite::class)->run(BackupTrigger::Command);

    expect($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and($result->place)->toBe('Place 2')
        ->and(BackupRun::query()->findOrFail($result->runId)->destination_id)->toBe($second->id)
        ->and($this->backups->runsAt(1))->toHaveCount(1)
        ->and($first->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($first->fresh()->last_error)->toBe('Teme found no backups at Place 1. Check its address, and that the storage is still there.')
        ->and($first->fresh()->last_answered_at)->toBeNull()
        ->and($second->fresh()->state)->toBe(BackupDestinationState::Ready);
});

it('refuses a place that holds other backups than Teme’s, and backs up at the next', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    [$first, $second] = $this->backups->places;
    BackupDestination::query()->whereKey($first->id)->update(['repository_id' => str_repeat('0', 64)]);

    $result = app(BackupSite::class)->run(BackupTrigger::Command);

    expect($result->succeeded)->toBeTrue((string) $result->sentence)
        ->and(BackupRun::query()->findOrFail($result->runId)->destination_id)->toBe($second->id)
        ->and($first->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($first->fresh()->last_error)->toBe('Place 1 holds other backups than the ones Teme made there.')
        // It answered: restic reached it.
        ->and($first->fresh()->last_answered_at)->not->toBeNull()
        ->and($this->backups->runsAt(0))->toBe([]);
});

it('fails the run, in words, when no place answers, backing nothing up', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->backups->repositories[0]->delete();

    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    $row = BackupRun::query()->findOrFail($result->runId);

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe('No place answered, so nothing was backed up. Teme found no backups at Place 1. Check its address, and that the storage is still there.')
        ->and($row->state)->toBe(BackupRunState::Failed)
        ->and($row->destination_id)->toBeNull()
        ->and($row->snapshot_id)->toBeNull()
        ->and($result->onlyMissingFiles())->toBeFalse();
});

it('counts a run with restic’s unread media as more than missing files, even beside one', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $this->media->image();
    $this->media->image([], withFiles: false);
    // Every program runs as it would, but restic's media backup exits 3, as when it couldn't read some files (I-2400).
    Process::fake(['*'.BackupSnapshots::MEDIA.'*' => Process::result('', ScriptedRestic::exitError(3, 'Fatal: unable to save snapshot: some source files could not be read'), 3)]);

    $result = app(BackupSite::class)->run(BackupTrigger::Command);

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toContain('wasn’t on this server')->toContain('restic couldn’t read some media files at Place 1')
        ->and($result->missingFiles)->toBe(1)
        ->and($result->onlyMissingFiles())->toBeFalse();
});

it('fails the run before reading anything when restic is missing, or a media disk isn’t on this server', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $restic = config('teme.backups.restic');
    config(['teme.backups.restic' => TemporaryRepository::freshFolder().DIRECTORY_SEPARATOR.'restic']);
    $missing = app(BackupSite::class)->run(BackupTrigger::Command);
    config(['teme.backups.restic' => $restic]);

    Storage::set((string) config('media.disk'), Mockery::mock(Filesystem::class));
    $elsewhere = app(BackupSite::class)->run(BackupTrigger::Command);

    expect($missing->succeeded)->toBeFalse()
        ->and($missing->sentence)->toContain('where TEME_RESTIC_BINARY says restic is')
        ->and($elsewhere->succeeded)->toBeFalse()
        ->and($elsewhere->sentence)->toBe('The media disk “'.config('media.disk').'” isn’t a folder on this server, so Teme can’t back its files up.')
        ->and(BackupRun::query()->where('state', BackupRunState::Failed->value)->count())->toBe(2)
        ->and($this->backups->runsAt(0))->toBe([]);
});

it('refuses while another backup runs, or before backups are set up, writing nothing', function (): void {
    $this->backups = RunnableBackups::atFolders(1, setUp: false);

    expect(fn () => app(BackupSite::class)->run(BackupTrigger::Command))
        ->toThrow(ValidationException::class, 'Finish setting backups up first.');

    BackupRecords::key($this->backups->places[0], ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    OtherSession::holdBackupLock();

    expect(fn () => app(BackupSite::class)->run(BackupTrigger::Command))
        ->toThrow(BackupAlreadyRunning::class, 'A backup is already running. Try again when it has finished.')
        ->and(BackupRun::query()->count())->toBe(0)
        ->and($this->backups->runsAt(0))->toBe([]);
});

it('writes nothing when authorization fails', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app()->forgetInstance(Elevation::class);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(BackupSite::class)->run(BackupTrigger::Person, (string) $editor->id))
        ->toThrow(AuthorizationException::class, 'Only an administrator manages backups.')
        ->and(BackupRun::query()->count())->toBe(0)
        ->and($this->backups->runsAt(0))->toBe([])
        ->and(OtherSession::backupRunning())->toBeFalse();
});

it('stops, in words, when the lock’s session is lost mid-run, writing nothing more to restic', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    // The lock's session ends as the run asks its first place, as a restarted server or a dropped connection ends it.
    $result = app(BackupSite::class)->run(BackupTrigger::Command, progress: function (string $step): void {
        if (str_starts_with($step, 'Asking ')) {
            DB::purge(BackupLock::CONNECTION);
        }
    });
    $row = BackupRun::query()->findOrFail($result->runId);

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe('This stopped because another backup, or a removal of old backups, may have started at the same time.')
        ->and($row->state)->toBe(BackupRunState::Failed)
        ->and($row->message)->toBe($result->sentence)
        ->and($this->backups->runsAt(0))->toBe([]);

    // A dropped session's lock goes only when its server process ends: wait, so the next test starts with it free.
    $until = microtime(true) + 5;

    while (OtherSession::backupRunning() && microtime(true) < $until) {
        usleep(100_000);
    }

    expect(OtherSession::backupRunning())->toBeFalse();
});

it('makes no backup when pg_dump fails, and says so, the place still answering', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = $this->backups->places[0]->repository_id;
    $server = (int) DB::scalar('SHOW server_version');
    // Every program answers as it would, but pg_dump, which restic runs, fails (I-2400: restic can't be made to do this on demand).
    Process::fake(function (PendingProcess $process) use ($id, $server) {
        $command = (array) $process->command;

        return match (true) {
            in_array('version', $command, true) => Process::result('{"message_type":"version","version":"0.19.1","go_version":"go1.26.4","go_os":"linux","go_arch":"amd64"}'),
            in_array('--version', $command, true) => Process::result("pg_dump (PostgreSQL) {$server}.0"),
            in_array('cat', $command, true) => Process::result(json_encode(['version' => 2, 'id' => $id, 'chunker_polynomial' => '25b468838dcb75'])),
            default => Process::result('', ScriptedRestic::exitError(1, 'stdin-from-command: command failed: exit status 1'), 1),
        };
    });

    $result = app(BackupSite::class)->run(BackupTrigger::Command);

    expect($result->succeeded)->toBeFalse()
        ->and($result->sentence)->toBe('pg_dump couldn’t copy the database, so nothing was backed up. The log has its own words.')
        ->and(BackupRun::query()->findOrFail($result->runId)->state)->toBe(BackupRunState::Failed)
        ->and($this->backups->places[0]->fresh()->state)->toBe(BackupDestinationState::Ready);

    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('--stdin', (array) $process->command, true));
});
