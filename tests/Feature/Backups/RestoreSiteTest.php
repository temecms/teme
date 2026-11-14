<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\BeginRestore;
use App\Domain\Backups\Operations\EndRestore;
use App\Domain\Backups\Operations\Input\BeginRestoreInput;
use App\Domain\Backups\Operations\Input\EndRestoreInput;
use App\Domain\Backups\Operations\Input\SettleRestoredSiteInput;
use App\Domain\Backups\Operations\SettleRestoredSite;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Restore\MaintenanceWindow;
use App\Domain\Backups\Restore\RestoreRefused;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Backups\Restore\RestoreStage;
use App\Domain\Backups\Restore\RestoreStopped;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Deployment\DeployLock;
use App\Domain\Media\MediaDisks;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\TemporaryRepository;
use Tests\Fixtures\Deployment\DeployLockSession;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->site = null;
    $this->backups = null;
    $this->media = new CommittedMedia;
    $this->folders = [];
    $this->later = null;
    // A scratch site with a person, an image and one place, backed up: the backup's run ID.
    $this->backedUp = function (): string {
        $this->site = ScratchSite::open();
        // The production setting: the flag lives in the table the restore replaces (docs/studies/deployment.md).
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database', 'page_cache.enabled' => true]);
        app()->forgetInstance(MaintenanceModeManager::class);
        $this->backups = RunnableBackups::atFolders(1);
        User::factory()->create(['name' => 'Before the backup']);
        $this->image = $this->media->image();
        $backup = app(BackupSite::class)->run(BackupTrigger::Command);
        expect($backup->succeeded)->toBeTrue((string) $backup->sentence);

        return $backup->runId;
    };
    // What happens to the site after the backup.
    $this->changeIt = function (): void {
        DB::table('users')->update(['name' => 'After the backup']);
        MediaDisks::originals()->put("{$this->image}/1/original.jpg", 'changed after the backup');
        MediaDisks::public()->put('stray/after-the-backup.webp', 'not in the backup');
        Cache::store('database')->put('teme.after', 'cached after the backup', 600);
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->later?->delete();
    $this->media->delete();
    OtherSession::close();
    DeployLockSession::close();
    $this->site?->close();

    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

it('restores the site end to end: the database and media as backed up, the caches emptied, the old runs settled, the places paused', function (): void {
    $runId = ($this->backedUp)();
    ($this->changeIt)();
    $steps = [];

    $result = app(RestoreSite::class)->run(new RestoreRequest, function (string $step) use (&$steps): void {
        $steps[] = $step;
    });

    $source = BackupRun::query()->findOrFail($runId);
    $restore = BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole();
    // The designed order: fetched while open, closed, then the safety backup, then the rest.
    $ours = ['Fetching the copy of the database from Place 1.', 'Closing the site for maintenance.', 'Making a safety backup of the site as it is now.', 'Clearing the page cache.', 'Restoring the database.', 'Recording the restore, and pausing backups until you say whether this server is now the site.', 'Restoring 3 media files from Place 1.', 'Clearing the caches and restarting the queue workers.', 'Opening the site again.'];

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($result->stage)->toBe(RestoreStage::DatabaseRestored)
        ->and($result->sentence)->toStartWith('Restored the backup of ')->toContain(' from Place 1: ')->toEndWith(' rows and 3 media files.')
        ->and($result->siteOpen)->toBeTrue()
        ->and($result->safetyRunId)->not->toBeNull()
        ->and($result->notes)->toContain("The site as it was before the restore is kept as backup {$result->safetyRunId}: php artisan teme:backup:restore {$result->safetyRunId} brings it back.")
        ->and(array_values(array_intersect($steps, $ours)))->toBe($ours)
        // The site as the backup holds it.
        ->and(DB::table('users')->pluck('name')->all())->toBe(['Before the backup'])
        ->and(MediaDisks::originals()->get("{$this->image}/1/original.jpg"))->toBe("original of {$this->image}")
        ->and(MediaDisks::public()->exists('stray/after-the-backup.webp'))->toBeFalse()
        ->and(Cache::store('database')->get('teme.after'))->toBeNull()
        ->and(app()->maintenanceMode()->active())->toBeFalse()
        ->and(DB::table('page_cache_purges')->where('tag', '*')->exists())->toBeTrue()
        // The run that made the backup, settled; the restore, recorded; the places, paused.
        ->and($source->state)->toBe(BackupRunState::Finished)
        ->and($source->snapshot_id)->toBe($this->backups->runsAt(0)[1]->parts[BackupSnapshots::MANIFEST])
        ->and($restore->state)->toBe(BackupRunState::Finished)
        ->and($restore->destination_id)->toBe($this->backups->places[0]->id)
        ->and($restore->figures['source_run'])->toBe($runId)
        ->and($restore->figures['safety_run'])->toBe($result->safetyRunId)
        ->and($restore->figures['migrations_run'])->toBe(0)
        ->and($restore->figures['media_files'])->toBe(3)
        ->and(app(BackupPlaces::class)->isPaused())->toBeTrue()
        ->and(DB::table('security_events')->whereIn('type', ['backup.restore_started', 'backup.restore_ended'])->orderBy('id')->pluck('type')->all())
        ->toBe(['backup.restore_started', 'backup.restore_ended']);
});

it('reopens the site unchanged when another backup holds the lock as the safety backup starts', function (): void {
    ($this->backedUp)();
    ($this->changeIt)();
    OtherSession::holdBackupLock();

    $held = app(RestoreSite::class)->run(new RestoreRequest);

    expect($held->succeeded)->toBeFalse()
        ->and($held->stage)->toBe(RestoreStage::SiteUntouched)
        ->and($held->sentence)->toBe('A backup is already running. Try again when it has finished. Nothing was changed: the site is as it was.')
        ->and($held->siteOpen)->toBeTrue()
        ->and(DB::table('users')->value('name'))->toBe('After the backup')
        ->and(app()->maintenanceMode()->active())->toBeFalse()
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->exists())->toBeFalse();
});

it('goes on when the safety backup’s only hole is a media file this server lost, saying it can’t bring that file back', function (): void {
    ($this->backedUp)();
    // The disaster a restore is for: the file is gone.
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");
    $steps = [];

    $result = app(RestoreSite::class)->run(new RestoreRequest, function (string $step) use (&$steps): void {
        $steps[] = $step;
    });
    $restore = BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole();
    $said = 'The safety backup lacks 1 media file the database names: it was already missing from this server.';
    $kept = "The site as it was before the restore is kept as backup {$result->safetyRunId}: php artisan teme:backup:restore {$result->safetyRunId} brings it back.";
    $lacks = 'That safety backup lacks 1 media file the database names, because it wasn’t on this server, so going back to it can’t bring that file back.';

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($result->safetyRunId)->not->toBeNull()
        ->and(MaintenanceWindow::unfinished())->toBeNull()
        ->and($result->notes)->toContain($kept)->toContain($lacks)
        ->and(array_search($lacks, $result->notes, true))->toBe(array_search($kept, $result->notes, true) + 1)
        ->and($restore->figures['safety_run'])->toBe($result->safetyRunId)
        ->and($restore->figures['safety_missing_files'])->toBe(1)
        ->and(MediaDisks::originals()->get("{$this->image}/1/original.jpg"))->toBe("original of {$this->image}")
        // Said at that step, before anything is replaced.
        ->and($steps)->toContain($said)
        ->and(array_search($said, $steps, true))->toBeLessThan(array_search('Clearing the page cache.', $steps, true));
});

it('names the files a lacking safety backup doesn’t hold when the restore stops after the database', function (): void {
    $runId = ($this->backedUp)();
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");

    $stopped = app(RestoreSite::class)->run(new RestoreRequest, function (string $step): void {
        if (str_starts_with($step, 'Restoring 3 media files')) {
            throw new RestoreStopped('The disk went away.');
        }
    });
    $safety = $stopped->safetyRunId;

    expect($stopped->succeeded)->toBeFalse()
        ->and($stopped->stage)->toBe(RestoreStage::DatabaseRestored)
        ->and($stopped->sentence)->toBe("The disk went away. The database is restored but the restore didn’t finish, so the site stays closed. Run php artisan teme:backup:restore {$runId} again to finish it, or php artisan teme:backup:restore {$safety} to go back to the site as it was. That safety backup doesn’t hold 1 media file that was already missing from this server.");
});

it('keeps the safety backup’s missing files across a run again that finishes the restore', function (): void {
    $runId = ($this->backedUp)();
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");

    $stopped = app(RestoreSite::class)->run(new RestoreRequest, function (string $step): void {
        if (str_starts_with($step, 'Restoring 3 media files')) {
            throw new RestoreStopped('The disk went away.');
        }
    });
    $finished = app(RestoreSite::class)->run(new RestoreRequest(runId: $runId));
    $restores = BackupRun::query()->where('kind', BackupRunKind::Restore->value)->get();

    expect($finished->succeeded)->toBeTrue($finished->sentence)
        ->and($finished->safetyRunId)->toBe($stopped->safetyRunId)
        ->and($restores->firstWhere('state', BackupRunState::Finished)->figures['safety_missing_files'])->toBe(1);
});

it('names the files a lacking safety backup doesn’t hold when the restore stops after the newer tables went', function (): void {
    ($this->backedUp)();
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");
    DB::statement('CREATE TABLE newer_things (id bigint PRIMARY KEY)');
    Process::fake(['*--single-transaction*' => Process::result(errorOutput: "pg_restore: error: out of disk space\n", exitCode: 1)]);

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->stage)->toBe(RestoreStage::TablesDropped)
        ->and($result->sentence)->toBe("pg_restore couldn’t restore the database. The log has its own words. The database wasn’t restored, but 1 table newer than the backup was removed first, so the site stays closed. Restore the safety backup to put it back: php artisan teme:backup:restore {$result->safetyRunId}. That safety backup doesn’t hold 1 media file that was already missing from this server.");
});

it('refuses, changing nothing, when the safety backup finds every media file missing, as an unmounted volume looks', function (): void {
    ($this->backedUp)();
    DB::table('users')->update(['name' => 'After the backup']);
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");
    MediaDisks::public()->delete(["{$this->image}/1/w640.webp", "{$this->image}/1/thumb.webp"]);

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->succeeded)->toBeFalse()
        ->and($result->stage)->toBe(RestoreStage::SiteUntouched)
        ->and($result->sentence)->toBe('Every media file the database names, all 3 of them, is missing from this server, which usually means the media folder isn’t there: a volume that isn’t mounted, say. Check it, or add --without-safety-backup to restore anyway if the files really are gone. Nothing was changed: the site is as it was.')
        ->and($result->siteOpen)->toBeTrue()
        ->and(app()->maintenanceMode()->active())->toBeFalse()
        ->and(DB::table('users')->value('name'))->toBe('After the backup')
        ->and(MediaDisks::originals()->exists("{$this->image}/1/original.jpg"))->toBeFalse();
});

it('refuses, changing nothing, when a media folder wasn’t there as the safety backup looked', function (): void {
    ($this->backedUp)();
    DB::table('users')->update(['name' => 'After the backup']);
    $folder = rtrim(MediaDisks::public()->path(''), '/\\');
    File::deleteDirectory($folder);

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->succeeded)->toBeFalse()
        ->and($result->stage)->toBe(RestoreStage::SiteUntouched)
        ->and($result->sentence)->toBe("The media folder {$folder} wasn’t on this server when the safety backup looked, which usually means a volume isn’t mounted. Check it, or add --without-safety-backup to restore anyway if the files really are gone. Nothing was changed: the site is as it was.")
        ->and($result->siteOpen)->toBeTrue()
        ->and(DB::table('users')->value('name'))->toBe('After the backup')
        ->and(MediaDisks::public()->exists("{$this->image}/1/w640.webp"))->toBeFalse();
});

it('restores with every media file missing when told to go on without a safety backup', function (): void {
    ($this->backedUp)();
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");
    MediaDisks::public()->delete(["{$this->image}/1/w640.webp", "{$this->image}/1/thumb.webp"]);

    $result = app(RestoreSite::class)->run(new RestoreRequest(withoutSafetyBackup: true));

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($result->safetyRunId)->toBeNull()
        ->and(MediaDisks::originals()->get("{$this->image}/1/original.jpg"))->toBe("original of {$this->image}");
});

it('records no missing files for a safety backup that lacked none', function (): void {
    ($this->backedUp)();
    ($this->changeIt)();

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole()->figures['safety_missing_files'])->toBe(0)
        ->and(implode(' ', $result->notes))->not->toContain('That safety backup lacks');
});

it('still stops, changing nothing, when the safety backup lacks lost media and has another hole too', function (): void {
    ($this->backedUp)();
    DB::table('users')->update(['name' => 'After the backup']);
    MediaDisks::originals()->delete("{$this->image}/1/original.jpg");

    // The dump is already fetched; then restic's media step exits 3, as when it couldn't read some files (I-2400).
    $result = app(RestoreSite::class)->run(new RestoreRequest, function (string $step): void {
        if ($step === 'Making a safety backup of the site as it is now.') {
            Process::fake(['*'.BackupSnapshots::MEDIA.'*' => Process::result('', ScriptedRestic::exitError(3, 'Fatal: unable to save snapshot: some source files could not be read'), 3)]);
        }
    });

    expect($result->succeeded)->toBeFalse()
        ->and($result->stage)->toBe(RestoreStage::SiteUntouched)
        ->and($result->sentence)->toStartWith('The safety backup failed: 1 media file the database names wasn’t on this server')
        ->and($result->sentence)->toContain('restic couldn’t read some media files at Place 1')
        ->and($result->sentence)->toEndWith(' Nothing was changed: the site is as it was.')
        ->and($result->siteOpen)->toBeTrue()
        ->and(DB::table('users')->value('name'))->toBe('After the backup');
});

it('still stops, changing nothing, when the safety backup fails another way', function (): void {
    ($this->backedUp)();
    ($this->changeIt)();

    // The dump is already fetched; then the place stops answering, so the safety backup has nowhere to go.
    $result = app(RestoreSite::class)->run(new RestoreRequest, function (string $step): void {
        if ($step === 'Making a safety backup of the site as it is now.') {
            $this->backups->repositories[0]->delete();
        }
    });

    expect($result->succeeded)->toBeFalse()
        ->and($result->stage)->toBe(RestoreStage::SiteUntouched)
        ->and($result->sentence)->toStartWith('The safety backup failed: No place answered, so nothing was backed up.')
        ->and($result->sentence)->toEndWith(' Nothing was changed: the site is as it was.')
        ->and($result->siteOpen)->toBeTrue()
        ->and(app()->maintenanceMode()->active())->toBeFalse()
        ->and(DB::table('users')->value('name'))->toBe('After the backup')
        ->and(MediaDisks::originals()->get("{$this->image}/1/original.jpg"))->toBe('changed after the backup');
});

it('leaves the site as it was, and open, when pg_restore fails with nothing removed', function (): void {
    ($this->backedUp)();
    ($this->changeIt)();
    Process::fake(['*--single-transaction*' => Process::result(errorOutput: "pg_restore: error: out of disk space\n", exitCode: 1)]);

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->succeeded)->toBeFalse()
        ->and($result->stage)->toBe(RestoreStage::SiteUntouched)
        ->and($result->sentence)->toBe('pg_restore couldn’t restore the database. The log has its own words. Nothing was changed: the site is as it was.')
        ->and($result->siteOpen)->toBeTrue()
        ->and(DB::table('users')->value('name'))->toBe('After the backup')
        ->and(app()->maintenanceMode()->active())->toBeFalse();
});

it('keeps the site closed, naming the safety backup, when pg_restore fails after the newer tables went', function (): void {
    ($this->backedUp)();
    DB::statement('CREATE TABLE newer_things (id bigint PRIMARY KEY)');
    Process::fake(['*--single-transaction*' => Process::result(errorOutput: "pg_restore: error: out of disk space\n", exitCode: 1)]);

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->stage)->toBe(RestoreStage::TablesDropped)
        ->and($result->sentence)->toBe("pg_restore couldn’t restore the database. The log has its own words. The database wasn’t restored, but 1 table newer than the backup was removed first, so the site stays closed. Restore the safety backup to put it back: php artisan teme:backup:restore {$result->safetyRunId}.")
        ->and($result->siteOpen)->toBeFalse()
        ->and(Schema::hasTable('newer_things'))->toBeFalse()
        ->and(app()->maintenanceMode()->active())->toBeTrue();
});

it('refuses while teme:deploy holds the deploy lock, before anything is written', function (): void {
    ($this->backedUp)();
    ($this->changeIt)();
    $runs = DB::table('backup_runs')->count();
    DeployLockSession::hold();

    expect(fn () => app(RestoreSite::class)->run(new RestoreRequest))->toThrow(RestoreRefused::class, RestoreSite::DEPLOY_RUNNING)
        // No restore run, no safety backup, no event: the site as it was, and open.
        ->and(DB::table('backup_runs')->count())->toBe($runs)
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->exists())->toBeFalse()
        ->and(DB::table('security_events')->where('type', 'backup.restore_started')->exists())->toBeFalse()
        ->and(app()->maintenanceMode()->active())->toBeFalse()
        ->and(DB::table('users')->pluck('name')->all())->toBe(['After the backup'])
        ->and(MediaDisks::originals()->get("{$this->image}/1/original.jpg"))->toBe('changed after the backup');
});

it('holds the deploy lock from the safety backup through the database, and lets go when it ends', function (): void {
    ($this->backedUp)();
    ($this->changeIt)();
    $taken = [];

    $result = app(RestoreSite::class)->run(new RestoreRequest, function (string $step) use (&$taken): void {
        if (in_array($step, ['Making a safety backup of the site as it is now.', 'Restoring the database.'], true)) {
            try {
                DeployLockSession::hold();
                $taken[$step] = 'by a deploy';
                DeployLockSession::close();
            } catch (RuntimeException) {
                $taken[$step] = 'by the restore';
            }
        }
    });

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($taken)->toBe(['Making a safety backup of the site as it is now.' => 'by the restore', 'Restoring the database.' => 'by the restore']);

    DeployLockSession::hold();
});

it('keeps the site closed when it stops after the database, and finishes when run again, without a second safety backup', function (): void {
    $runId = ($this->backedUp)();
    ($this->changeIt)();

    $stopped = app(RestoreSite::class)->run(new RestoreRequest, function (string $step): void {
        if (str_starts_with($step, 'Restoring 3 media files')) {
            throw new RuntimeException('The disk went away.');
        }
    });
    $safety = $stopped->safetyRunId;

    expect($stopped->succeeded)->toBeFalse()
        ->and($stopped->stage)->toBe(RestoreStage::DatabaseRestored)
        ->and($stopped->sentence)->toBe("The restore stopped on an error Teme didn’t expect. The log has its details. The database is restored but the restore didn’t finish, so the site stays closed. Run php artisan teme:backup:restore {$runId} again to finish it, or php artisan teme:backup:restore {$safety} to go back to the site as it was.")
        ->and($stopped->siteOpen)->toBeFalse()
        ->and(app()->maintenanceMode()->active())->toBeTrue()
        ->and(MaintenanceWindow::unfinished())->toMatchArray(['run' => $runId, 'safety' => $safety])
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole()->state)->toBe(BackupRunState::Failed)
        ->and(DB::table('users')->value('name'))->toBe('Before the backup');

    $finished = app(RestoreSite::class)->run(new RestoreRequest(runId: $runId));

    expect($finished->succeeded)->toBeTrue($finished->sentence)
        ->and($finished->safetyRunId)->toBe($safety)
        ->and($finished->notes[0])->toStartWith("A restore of {$runId} didn’t finish, and the site has been closed since ")
        ->and($this->backups->runsAt(0))->toHaveCount(2)
        ->and(MediaDisks::originals()->get("{$this->image}/1/original.jpg"))->toBe("original of {$this->image}")
        ->and(app()->maintenanceMode()->active())->toBeFalse();
});

it('goes back to the safety backup at a place the restored database doesn’t know, holding what was edited during the download', function (): void {
    $runId = ($this->backedUp)();
    // A place added after the backup, tried first, so the safety backup lands there.
    $this->later = TemporaryRepository::make();
    BackupRecords::key(BackupRecords::destination(['name' => 'Added later', 'location' => ['path' => $this->later->folder], 'repository_id' => $this->later->id, 'created_at' => now()->subDay()]));

    $stopped = app(RestoreSite::class)->run(new RestoreRequest(place: 'Place 1', runId: $runId), function (string $step): void {
        if (str_starts_with($step, 'Fetching the copy')) {
            DB::table('users')->update(['name' => 'Edited during the download']);
        }

        if (str_starts_with($step, 'Restoring 3 media files')) {
            throw new RuntimeException('The disk went away.');
        }
    });

    expect($stopped->stage)->toBe(RestoreStage::DatabaseRestored)
        ->and(MaintenanceWindow::unfinished()['safety_place'])->toMatchArray(['name' => 'Added later', 'kind' => 'folder', 'repository_id' => $this->later->id])
        ->and(DB::table('backup_destinations')->where('name', 'Added later')->exists())->toBeFalse();

    $back = app(RestoreSite::class)->run(new RestoreRequest(runId: $stopped->safetyRunId));

    expect($back->succeeded)->toBeTrue($back->sentence)
        ->and($back->sentence)->toContain(' from Added later: ')
        ->and(DB::table('users')->value('name'))->toBe('Edited during the download')
        ->and(app()->maintenanceMode()->active())->toBeFalse();
});

it('runs this Teme’s newer migrations on an older backup, and leaves the operator’s own maintenance on', function (): void {
    ($this->backedUp)();
    // This Teme gains a migration after the backup, run here, as an update does.
    $this->folders[] = $folder = TemporaryRepository::freshFolder();
    is_dir($folder) || mkdir($folder, 0700, true);
    file_put_contents($folder.'/2099_01_01_000000_create_restore_probe_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('restore_probe', function (Blueprint $table): void {
                    $table->id();
                });
            }
        };
        PHP);
    app('migrator')->path($folder);
    Artisan::call('migrate', ['--force' => true]);
    DB::table('restore_probe')->insert(['id' => 7]);
    $theirs = ['except' => [], 'redirect' => null, 'retry' => 30, 'refresh' => null, 'secret' => 'the-operators', 'status' => 503, 'template' => null];
    app()->maintenanceMode()->activate($theirs);
    $steps = [];

    $result = app(RestoreSite::class)->run(new RestoreRequest(withoutSafetyBackup: true), function (string $step) use (&$steps): void {
        $steps[] = $step;
    });

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($result->safetyRunId)->toBeNull()
        ->and($steps)->toContain('Removing 1 table the backup doesn’t have.')
        ->and($steps)->toContain('Running this Teme’s newer migrations.')
        ->and($steps)->toContain('Leaving the site in maintenance, as it was before the restore.')
        ->and(BackupRun::query()->where('kind', BackupRunKind::Restore->value)->sole()->figures['migrations_run'])->toBe(1)
        ->and(Schema::hasTable('restore_probe'))->toBeTrue()
        ->and(DB::table('restore_probe')->count())->toBe(0)
        ->and(app()->maintenanceMode()->data())->toBe($theirs)
        ->and($result->siteOpen)->toBeFalse();
});

it('stops before this Teme’s newer migrations when the deploy lock is lost, the database restored', function (): void {
    $runId = ($this->backedUp)();
    // This Teme gains a migration after the backup, as in the test above.
    $this->folders[] = $folder = TemporaryRepository::freshFolder();
    is_dir($folder) || mkdir($folder, 0700, true);
    file_put_contents($folder.'/2099_01_01_000000_create_restore_probe_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('restore_probe', function (Blueprint $table): void {
                    $table->id();
                });
            }
        };
        PHP);
    app('migrator')->path($folder);
    Artisan::call('migrate', ['--force' => true]);

    $result = app(RestoreSite::class)->run(new RestoreRequest(withoutSafetyBackup: true), function (string $step): void {
        if ($step === 'Running this Teme’s newer migrations.') {
            // The restore's own lock session ends, as a killed backend's does; the backup lock's stays.
            $pid = DB::connection(DeployLock::CONNECTION)->scalar('SELECT pg_backend_pid()');
            DB::select('SELECT pg_terminate_backend(?)', [$pid]);
        }
    });

    expect($result->succeeded)->toBeFalse()
        ->and($result->stage)->toBe(RestoreStage::DatabaseRestored)
        ->and($result->sentence)->toBe("The deploy lock was lost, so the restore stopped before running migrations: a deploy may have started beside it. The database is restored but the restore didn’t finish, so the site stays closed. Run php artisan teme:backup:restore {$runId} again to finish it.")
        ->and($result->siteOpen)->toBeFalse()
        ->and(Schema::hasTable('restore_probe'))->toBeFalse();
});

it('settles what the old server left running, and pauses every place', function (): void {
    $this->seed(RoleSeeder::class);
    $place = BackupRecords::destination(['name' => 'This server']);
    $gone = BackupRecords::destination(['state' => BackupDestinationState::Removed, 'removed_at' => now()]);
    $source = BackupRecords::run(['state' => BackupRunState::Running, 'ended_at' => null, 'started_at' => now()->subHour()]);
    $prune = BackupRecords::run(['kind' => BackupRunKind::Prune, 'state' => BackupRunState::Running, 'ended_at' => null, 'started_at' => now()->subMinutes(50)]);
    $madeAt = CarbonImmutable::now()->subMinutes(55);

    $restoreId = app(SettleRestoredSite::class)->execute(new SettleRestoredSiteInput($source->id, str_repeat('a', 64), $madeAt, CarbonImmutable::now(), 'This server', $place->repository_id, null));

    expect($source->fresh()->state)->toBe(BackupRunState::Finished)
        ->and($source->fresh()->snapshot_id)->toBe(str_repeat('a', 64))
        ->and(abs($source->fresh()->ended_at->timestamp - $madeAt->timestamp))->toBeLessThanOrEqual(1)
        ->and($prune->fresh()->state)->toBe(BackupRunState::Failed)
        ->and($prune->fresh()->message)->toBe(SettleRestoredSite::UNKNOWN)
        ->and($place->fresh()->paused_at)->not->toBeNull()
        ->and($gone->fresh()->paused_at)->toBeNull()
        ->and(BackupRun::query()->findOrFail($restoreId)->kind)->toBe(BackupRunKind::Restore)
        ->and(BackupRun::query()->findOrFail($restoreId)->destination_id)->toBe($place->id)
        ->and(json_decode((string) DB::table('security_events')->where('type', 'backup.restore_started')->value('context'), true))
        ->toMatchArray(['run' => $source->id, 'place' => 'This server', 'restore' => $restoreId]);
});

it('writes nothing when authorization fails', function (Closure $operation): void {
    $this->seed(RoleSeeder::class);
    $source = BackupRecords::run(['state' => BackupRunState::Running, 'ended_at' => null]);
    $place = BackupRecords::destination();
    $admin = User::factory()->create()->assignRole('administrator');
    $state = fn (): string => json_encode([DB::table('backup_runs')->orderBy('id')->get(), DB::table('backup_destinations')->orderBy('id')->get(), DB::table('security_events')->get()], JSON_THROW_ON_ERROR);
    $before = $state();

    // An administrator is refused too: restoring is the operator's alone.
    [$class, $input] = $operation($source->id, $place->repository_id, (string) $admin->id);

    expect(fn () => app($class)->execute($input))->toThrow(AuthorizationException::class, RestoreSite::OPERATOR_ONLY)
        ->and($state())->toBe($before);
})->with([
    'BeginRestore' => [fn (string $run, string $repository, string $admin): array => [BeginRestore::class, new BeginRestoreInput($run, 'Place', CarbonImmutable::now(), $admin)]],
    'SettleRestoredSite' => [fn (string $run, string $repository, string $admin): array => [SettleRestoredSite::class, new SettleRestoredSiteInput($run, null, CarbonImmutable::now(), CarbonImmutable::now(), 'Place', $repository, null, $admin)]],
    'EndRestore' => [fn (string $run, string $repository, string $admin): array => [EndRestore::class, new EndRestoreInput(null, $run, false, 'x', [], $admin)]],
]);

it('restores and plans for the operator alone, writing nothing for anyone else', function (): void {
    $this->seed(RoleSeeder::class);
    $state = fn (): string => json_encode([DB::table('backup_runs')->orderBy('id')->get(), DB::table('security_events')->get()], JSON_THROW_ON_ERROR);
    $before = $state();
    app()->forgetInstance(Elevation::class);

    expect(fn () => app(RestoreSite::class)->preflight(new RestoreRequest))->toThrow(AuthorizationException::class, RestoreSite::OPERATOR_ONLY)
        ->and(fn () => app(RestoreSite::class)->run(new RestoreRequest))->toThrow(AuthorizationException::class, RestoreSite::OPERATOR_ONLY)
        ->and($state())->toBe($before);
});
