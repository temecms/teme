<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Restore\DatabaseRestore;
use App\Domain\Backups\Restore\MaintenanceWindow;
use App\Domain\Backups\Restore\RestoreFiles;
use App\Domain\Backups\Restore\RestoreStopped;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;

uses(RefreshDatabase::class);

/** A backup of the scratch site as it is now, its dump fetched into $files; pg_restore's list of it. */
function drBackUp(RunnableBackups $backups, RestoreFiles $files): array
{
    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    expect($result->succeeded)->toBeTrue((string) $result->sentence);
    $run = $backups->runsAt(0)[0];
    $database = app(DatabaseRestore::class);
    $database->download($backups->repositories[0]->repository, $run->parts[BackupSnapshots::DATABASE], $files->path(RestoreFiles::DUMP), 'Place 1');

    return $database->contents($files->path(RestoreFiles::DUMP));
}

/** Writes the use-list for $contents and restores the dump, as the restore does. */
function drRestore(RestoreFiles $files, array $contents): void
{
    $kept = DatabaseRestore::keptTables();
    $files->write(RestoreFiles::LIST, implode("\n", DatabaseRestore::useList($contents, $kept, DatabaseRestore::indexesOf($kept), DatabaseRestore::installedExtensions()))."\n");
    app(DatabaseRestore::class)->restore($files->path(RestoreFiles::DUMP), $files->path(RestoreFiles::LIST));
}

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->site = null;
    $this->backups = null;
    $this->files = null;
    $this->window = null;
});

afterEach(function (): void {
    $this->window?->reopen();
    $this->files?->remove();
    $this->backups?->delete();
    $this->site?->close();
});

it('leaves the cache’s tables and the extensions already here out of pg_restore’s list, and keeps the rest', function (): void {
    $this->site = ScratchSite::open();
    $this->backups = RunnableBackups::atFolders(1);
    $this->files = RestoreFiles::make();

    $contents = drBackUp($this->backups, $this->files);
    $kept = DatabaseRestore::keptTables();
    $list = implode("\n", DatabaseRestore::useList($contents, $kept, DatabaseRestore::indexesOf($kept), DatabaseRestore::installedExtensions()));

    expect($kept)->toBe(['cache', 'cache_locks'])
        ->and(DatabaseRestore::indexesOf($kept))->toContain('cache_expiration_index')
        ->and(DatabaseRestore::installedExtensions())->toContain('pg_trgm')
        ->and(implode("\n", $contents))->toMatch('/ TABLE public cache /')->toMatch('/ EXTENSION - pg_trgm/')
        ->and($list)->not->toMatch('/ TABLE public cache( |$)/m')
        ->and($list)->not->toMatch('/ TABLE public cache_locks( |$)/m')
        ->and($list)->not->toMatch('/ INDEX public cache_expiration_index/')
        ->and($list)->not->toMatch('/ CONSTRAINT public cache /')
        ->and($list)->not->toMatch('/ EXTENSION - pg_trgm/')
        ->and($list)->toMatch('/ TABLE public users( |$)/m')
        ->and($list)->toMatch('/ TABLE DATA public users( |$)/m')
        ->and(count(DatabaseRestore::useList($contents, [], [], [])))->toBe(count($contents));
});

it('drops the tables the backup lacks, then restores, so a newer table can’t block it', function (): void {
    $this->site = ScratchSite::open();
    $this->backups = RunnableBackups::atFolders(1);
    $this->files = RestoreFiles::make();
    $before = User::factory()->create(['name' => 'Before the backup']);
    $migrations = DB::table('migrations')->count();

    $contents = drBackUp($this->backups, $this->files);
    $before->update(['name' => 'After the backup']);
    User::factory()->create();
    // A table a newer migration made, with a key into one the dump holds.
    DB::statement('CREATE TABLE newer_things (id bigint PRIMARY KEY, user_id bigint REFERENCES users (id))');
    DB::table('newer_things')->insert(['id' => 1, 'user_id' => $before->id]);

    $lacking = DatabaseRestore::tablesTheDumpLacks($contents, DatabaseRestore::keptTables());
    app(DatabaseRestore::class)->dropTables($lacking);
    drRestore($this->files, $contents);

    expect($lacking)->toBe(['newer_things'])
        ->and(Schema::hasTable('newer_things'))->toBeFalse()
        ->and(DB::table('users')->pluck('name')->all())->toBe(['Before the backup'])
        ->and(DB::table('migrations')->count())->toBe($migrations)
        ->and(DB::table('backup_destinations')->count())->toBe(1);
});

it('holds the maintenance flag across the restore on the database store, and empties the rest of the cache', function (): void {
    $this->site = ScratchSite::open();
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    app()->forgetInstance(MaintenanceModeManager::class);
    $this->backups = RunnableBackups::atFolders(1);
    $this->files = RestoreFiles::make();
    $contents = drBackUp($this->backups, $this->files);
    Cache::store('database')->put('teme.settings.general', 'cached before', 600);

    $this->window = MaintenanceWindow::open(['run' => 'r', 'safety' => null, 'safety_place' => null, 'since' => '2026-10-18T02:00:00+00:00']);
    drRestore($this->files, $contents);

    expect(app()->maintenanceMode()->active())->toBeTrue()
        ->and(MaintenanceWindow::unfinished())->toBe(['run' => 'r', 'safety' => null, 'safety_place' => null, 'since' => '2026-10-18T02:00:00+00:00'])
        ->and(Cache::store('database')->get('teme.settings.general'))->toBe('cached before');

    app(DatabaseRestore::class)->emptyCache();

    expect($this->window->holds())->toBeTrue()
        ->and(Cache::store('database')->get('teme.settings.general'))->toBeNull()
        ->and(DB::table('cache')->count())->toBe(1)
        ->and(DB::table('cache')->value('key'))->toBe(MaintenanceWindow::flagKey('database'));
});

it('changes nothing when pg_restore fails', function (): void {
    $this->site = ScratchSite::open();
    $this->backups = RunnableBackups::atFolders(1);
    $this->files = RestoreFiles::make();
    $user = User::factory()->create(['name' => 'Before the backup']);
    $contents = drBackUp($this->backups, $this->files);
    $user->update(['name' => 'After the backup']);
    Process::fake(['*--single-transaction*' => Process::result(errorOutput: "pg_restore: error: could not execute query\n", exitCode: 1)]);

    expect(fn () => drRestore($this->files, $contents))
        ->toThrow(RestoreStopped::class, 'pg_restore couldn’t restore the database. The log has its own words.')
        ->and(DB::table('users')->value('name'))->toBe('After the backup');
});

it('restores as a role that isn’t a superuser, as production’s is', function (): void {
    $this->site = ScratchSite::open(asRestrictedRole: true);
    $this->backups = RunnableBackups::atFolders(1);
    $this->files = RestoreFiles::make();
    $user = User::factory()->create(['name' => 'Before the backup']);
    $contents = drBackUp($this->backups, $this->files);
    $user->update(['name' => 'After the backup']);

    drRestore($this->files, $contents);

    expect(DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper)->toBeFalse()
        ->and(DB::table('users')->value('name'))->toBe('Before the backup')
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_extension WHERE extname = 'pg_trgm'")->n)->toBe(1);
});

it('closes the site with its marker and a bypass secret, and puts the operator’s own maintenance back', function (): void {
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->forgetInstance(MaintenanceModeManager::class);
    $mode = app()->maintenanceMode();
    $theirs = ['except' => [], 'redirect' => null, 'retry' => 30, 'refresh' => null, 'secret' => 'the-operators', 'status' => 503, 'template' => null];
    $mode->activate($theirs);
    $marker = ['run' => 'r', 'safety' => null, 'safety_place' => null, 'since' => '2026-10-18T02:00:00+00:00'];
    $place = ['name' => 'Offsite', 'kind' => 'folder', 'location' => ['path' => '/srv/backups'], 'repository_id' => str_repeat('a', 64)];

    $window = MaintenanceWindow::open($marker);

    expect($window->wasDownBefore())->toBeTrue()
        ->and($window->bypassSecret())->toMatch('/^[0-9a-f]{32}$/')
        ->and($mode->data()['secret'])->toBe($window->bypassSecret())
        ->and($mode->data()['status'])->toBe(503)
        ->and(MaintenanceWindow::unfinished())->toBe($marker)
        ->and(MaintenanceWindow::flagKey('array'))->toEndWith('illuminate:foundation:down')
        ->and(MaintenanceWindow::flagKey('database'))->toBeNull();

    // The safety backup joins the marker once it's made, with its place and never its credentials.
    $window->record('the-safety-run', $place);

    expect(MaintenanceWindow::unfinished())->toBe([...$marker, 'safety' => 'the-safety-run', 'safety_place' => $place])
        ->and($window->holds())->toBeTrue();

    // A rerun opens over the unfinished window, and still knows the operator's own.
    $again = MaintenanceWindow::open($marker);
    Cache::store('array')->flush();

    expect($again->holds())->toBeFalse();

    $again->reassert();

    expect($again->holds())->toBeTrue();

    $again->reopen();

    expect($mode->active())->toBeTrue()
        ->and($mode->data())->toBe($theirs)
        ->and(MaintenanceWindow::unfinished())->toBeNull();

    $mode->deactivate();
    MaintenanceWindow::open($marker)->reopen();

    expect($mode->active())->toBeFalse();
});

it('keeps a restore’s two files in a private folder and removes them by name', function (): void {
    $files = RestoreFiles::make();
    $files->write(RestoreFiles::LIST, "1; 2 3 TABLE public users teme\n");
    file_put_contents($files->path(RestoreFiles::DUMP), 'dump');

    expect(dirname($files->folder))->toBe(storage_path('app'.DIRECTORY_SEPARATOR.'restore'))
        ->and(PHP_OS_FAMILY === 'Windows' || (fileperms($files->folder) & 0777) === 0700)->toBeTrue()
        ->and(fn () => $files->path('elsewhere.txt'))->toThrow(LogicException::class, 'Only the dump and the list are kept in a restore’s folder.');

    $files->remove();

    expect(is_dir($files->folder))->toBeFalse();
});
