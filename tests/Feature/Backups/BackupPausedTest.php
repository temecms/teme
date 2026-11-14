<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Input\RaiseBackupAlertInput;
use App\Domain\Backups\Operations\RaiseBackupAlert;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Backups\Queries\DueVerification;
use App\Domain\Backups\Verify\VerifyBackups;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'UTC'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    VerifyDatabase::unnamed();
    app()->instance(Elevation::class, new OperatorElevation);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
    $this->place = BackupRecords::destination();
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => now()->subDays(3)]);
    // That night's backup, finished.
    BackupRecords::run();
});

it('is red while backups are paused, with the restore’s words and how to answer, and never emails for it', function (): void {
    Notification::fake();
    $this->place->forceFill(['paused_at' => now()])->save();

    $verdict = app(BackupStatus::class)->verdict();

    expect($verdict->problems)->toBe([BackupPlaces::PAUSED.' Answer on Tools › Backups.'])
        ->and($verdict->alerting)->toBe([])
        ->and(app(RaiseBackupAlert::class)->execute(new RaiseBackupAlertInput))->toBeNull()
        // Two days on, no backup has started, as the pause means: still no email.
        ->and(app(BackupStatus::class)->verdict(CarbonImmutable::now()->addDays(2))->alerting)->toBe([]);

    Notification::assertNothingSent();
});

it('starts no check while paused, and refuses one asked for, writing nothing', function (): void {
    config(['database.connections.teme_verify.database' => 'teme_verify']);
    $due = app(DueVerification::class);

    expect($due->readBackDue(CarbonImmutable::now()))->toBeTrue()
        ->and($due->testRestoreDue(CarbonImmutable::now()))->toBeTrue();

    $this->place->forceFill(['paused_at' => now()])->save();
    $before = BackupRun::query()->count();

    expect($due->readBackDue(CarbonImmutable::now()))->toBeFalse()
        ->and($due->testRestoreDue(CarbonImmutable::now()))->toBeFalse()
        ->and(fn () => app(VerifyBackups::class)->readBack(BackupTrigger::Command))->toThrow(ValidationException::class, BackupPlaces::PAUSED)
        ->and(BackupRun::query()->count())->toBe($before);
});
