<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The backups made before each command run as the operator, as the commands do.
    app()->instance(Elevation::class, new OperatorElevation);
    $this->site = null;
    $this->backups = null;
    $this->backedUp = function (): string {
        $this->site = ScratchSite::open();
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
        app()->forgetInstance(MaintenanceModeManager::class);
        $this->backups = RunnableBackups::atFolders(1);
        User::factory()->create(['name' => 'Before the backup']);

        return app(BackupSite::class)->run(BackupTrigger::Command)->runId;
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->site?->close();
});

it('shows what it will do, asks, restores, then asks whether this server is now the site', function (): void {
    ($this->backedUp)();
    DB::table('users')->update(['name' => 'After the backup']);

    $this->artisan('teme:backup:restore')
        ->expectsOutputToContain('This replaces the site’s database and media with the backup of ')
        ->expectsOutput('First, Teme backs the site up as it is now, so the restore can be undone.')
        ->expectsConfirmation('Restore this backup?', 'yes')
        ->expectsOutputToContain('Restored the backup of ')
        ->expectsOutput('Backups are paused: the restored site’s places are the old site’s too, and two sites must never back up into one place.')
        ->expectsChoice('Is this server now the site?', 'This server is now the site', ['This server is now the site', 'This is a copy: remove these backup places', 'Decide later'])
        ->expectsOutput('Backups resume at Place 1.')
        ->assertExitCode(0);

    expect(DB::table('users')->value('name'))->toBe('Before the backup')
        ->and(app(BackupPlaces::class)->isPaused())->toBeFalse();
});

it('restores with --force without asking, and says how to answer the pause', function (): void {
    ($this->backedUp)();

    $this->artisan('teme:backup:restore', ['--force' => true, '--without-safety-backup' => true])
        ->expectsOutput('No safety backup: --without-safety-backup.')
        ->expectsOutputToContain('Restored the backup of ')
        ->expectsOutput('Say which with php artisan teme:backup:restored site, or php artisan teme:backup:restored copy.')
        ->assertExitCode(0);

    expect(app(BackupPlaces::class)->isPaused())->toBeTrue();
});

it('refuses without a person at the terminal, and restores nothing when told no', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);

    $this->artisan('teme:backup:restore', ['--no-interaction' => true])
        ->expectsOutput('This replaces the whole site, so it needs a person at the terminal to confirm, or --force.')
        ->assertExitCode(1);

    $this->artisan('teme:backup:restore')
        ->expectsOutput('This server’s database holds nothing yet, so there’s nothing to back up first.')
        ->expectsConfirmation('Restore this backup?', 'no')
        ->expectsOutput('Nothing was restored.')
        ->assertExitCode(1);
});

it('prints each problem the preflight finds, exit 1', function (): void {
    $this->artisan('teme:backup:restore', ['--force' => true])
        ->expectsOutput('There’s no place backups are kept, so there’s nothing to restore from. On a new server, name the place with --kind.')
        ->assertExitCode(1);
});
