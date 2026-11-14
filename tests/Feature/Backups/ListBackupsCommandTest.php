<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Backups\RunnableBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->backups = null;
});

afterEach(function (): void {
    $this->backups?->delete();
});

it('lists a place’s backups newest first, marking one that can’t be restored', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $older = app(BackupSite::class)->run(BackupTrigger::Command);
    $newer = app(BackupSite::class)->run(BackupTrigger::Command);
    $runs = $this->backups->runsAt(0);
    // The older run without its manifest: incomplete.
    app(Restic::class)->run($this->backups->repositories[0]->repository, ['forget', $runs[1]->parts[BackupSnapshots::MANIFEST]]);
    $zone = (string) app(SettingsReader::class)->string('general', 'timezone');
    $at = fn (int $index): string => CarbonImmutable::parse($runs[$index]->time)->setTimezone($zone)->format('Y-m-d H:i');

    $this->artisan('teme:backup:list')
        ->expectsOutput('Backups at Place 1, newest first:')
        ->expectsOutput("{$newer->runId}  {$at(0)}")
        ->expectsOutput("{$older->runId}  {$at(1)}  (incomplete: it can’t be restored)")
        ->assertExitCode(0);
});

it('says a place holds none, and refuses one it doesn’t know', function (): void {
    $this->backups = RunnableBackups::atFolders(1);

    $this->artisan('teme:backup:list')->expectsOutput('Place 1 holds no backups yet.')->assertExitCode(0);
    $this->artisan('teme:backup:list', ['--place' => 'Nowhere'])->expectsOutput('There’s no place called “Nowhere”.')->assertExitCode(1);
});
