<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\Restore\MaintenanceWindow;
use App\Domain\Backups\Restore\MediaCheck;
use App\Domain\Backups\Restore\MediaRestore;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Backups\Restore\RestoreStage;
use App\Domain\Backups\Restore\SmokeTest;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Media\MediaDisks;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->media = new CommittedMedia;
    [$this->site, $this->backups, $this->window] = [null, null, null];
});

afterEach(function (): void {
    $this->window?->reopen();
    $this->backups?->delete();
    $this->media->delete();
    $this->site?->close();
});

it('names an original missing or changed, and an image size missing, without failing', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $first = $this->media->image();
    $second = $this->media->image();
    app(BackupSite::class)->run(BackupTrigger::Command);
    $run = $this->backups->runsAt(0)[0];
    $manifest = Manifest::fromJson(json_encode(app(Restic::class)->run($this->backups->repositories[0]->repository, ['dump', $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE])->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    MediaDisks::originals()->delete("{$first}/1/original.jpg");
    MediaDisks::originals()->put("{$second}/1/original.jpg", 'changed since');
    MediaDisks::public()->delete("{$second}/1/thumb.webp");

    $checked = app(MediaCheck::class)->check($manifest, MediaRestore::pairs($manifest)['pairs']);

    expect($checked['counts'])->toBe(['originals_missing' => 1, 'originals_changed' => 1, 'variants_missing' => 1])
        ->and($checked['originals_missing'])->toBe(["originals:{$first}/1/original.jpg"])
        ->and($checked['originals_changed'])->toBe(["originals:{$second}/1/original.jpg"])
        ->and($checked['variants_missing'])->toBe(["public:{$second}/1/thumb.webp"]);
});

it('checks the home page answers behind the bypass, and says when a page doesn’t', function (): void {
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->forgetInstance(MaintenanceModeManager::class);
    $this->window = MaintenanceWindow::open(['run' => 'r', 'safety' => null, 'safety_place' => null, 'since' => '2026-10-18T02:00:00+00:00']);

    expect(app(SmokeTest::class)->run($this->window->bypassSecret()))->toBeNull()
        ->and(app(SmokeTest::class)->run('not-the-secret'))->toBe('The restored site doesn’t answer as it should: '.url('/').' answered 503.');
});

it('keeps the site closed when the restored site doesn’t answer', function (): void {
    $this->site = ScratchSite::open();
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    app()->forgetInstance(MaintenanceModeManager::class);
    $this->backups = RunnableBackups::atFolders(1);
    $runId = app(BackupSite::class)->run(BackupTrigger::Command)->runId;
    // The home page breaks after the restore.
    Route::get('/', fn () => abort(500));

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->stage)->toBe(RestoreStage::DatabaseRestored)
        ->and($result->sentence)->toStartWith('The restored site doesn’t answer as it should: '.url('/').' answered 500. The database is restored but the restore didn’t finish, so the site stays closed. Run php artisan teme:backup:restore '.$runId.' again to finish it')
        ->and(app()->maintenanceMode()->active())->toBeTrue();
});
