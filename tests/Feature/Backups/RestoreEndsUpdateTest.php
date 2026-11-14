<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreSite;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Models\User;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\OtherSession;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScratchSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    Notification::fake();
    $this->site = null;
    $this->backups = null;
    $this->media = new CommittedMedia;
    // A scratch site backed up while an update is open at deploy, as a deploy's own backup is.
    $this->backedUp = function (bool $withUpdates): void {
        $this->site = ScratchSite::open();
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database', 'page_cache.enabled' => true]);
        app()->forgetInstance(MaintenanceModeManager::class);
        $this->backups = RunnableBackups::atFolders(1);
        $this->admin = User::factory()->create()->assignRole('administrator');
        $this->media->image();

        if ($withUpdates) {
            DB::table('teme_updates')->insert([
                'id' => '01j9zzzzzzzzzzzzzzzzzzzzzz', 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => '0.1.0-rc.15', 'status' => 'running',
                'step' => 'deploy', 'steps' => '[]', 'requested_at' => now(), 'started_at' => now(),
            ]);
        } else {
            DB::statement('DROP TABLE teme_updates');
        }

        $backup = app(BackupSite::class)->run(BackupTrigger::Command);
        expect($backup->succeeded)->toBeTrue((string) $backup->sentence);
    };
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->media->delete();
    OtherSession::close();
    $this->site?->close();
});

it('ends an update the backup holds open, saying so', function (): void {
    ($this->backedUp)(true);
    // The updater has moved on since the backup, which was taken while the update ran.
    DB::table('teme_updates')->update(['status' => 'done', 'finished_at' => now()]);

    $result = app(RestoreSite::class)->run(new RestoreRequest);
    $row = DB::table('teme_updates')->first();

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and($row->status)->toBe('failed')
        ->and($row->error)->toBe('A restore ended this update.')
        ->and($row->finished_at)->not->toBeNull();
    Notification::assertSentTo($this->admin, TemeUpdateNotice::class, fn (TemeUpdateNotice $notice): bool => $notice->kind === 'failed' && $notice->sentence === 'A restore ended this update.');
});

it('changes nothing about updates when the backup is from before the updater', function (): void {
    ($this->backedUp)(false);
    DB::statement('CREATE TABLE teme_updates (id bigint PRIMARY KEY)');

    $result = app(RestoreSite::class)->run(new RestoreRequest);

    expect($result->succeeded)->toBeTrue($result->sentence)
        ->and(Schema::hasTable('teme_updates'))->toBeFalse();
    Notification::assertNothingSent();
});
