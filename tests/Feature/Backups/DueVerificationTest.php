<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Queries\DueVerification;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'Africa/Lagos'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    VerifyDatabase::unnamed();
    $this->place = BackupRecords::destination();
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $this->due = fn (): DueVerification => app(DueVerification::class);
    // 02:00 in Lagos is 01:00 UTC.
    $this->at = fn (string $utc): CarbonImmutable => CarbonImmutable::parse($utc, 'UTC');
    $this->backupAt = fn (string $utc) => BackupRecords::run(['started_at' => ($this->at)($utc), 'ended_at' => ($this->at)($utc)->addMinutes(20)]);
});

it('is due to read back each night once that night’s backup has started, and once only', function (): void {
    $now = ($this->at)('2026-10-18 03:00');

    expect(($this->due)()->readBackDue($now))->toBeFalse();

    ($this->backupAt)('2026-10-18 01:00:30');

    expect(($this->due)()->readBackDue($now))->toBeTrue();

    // A failed read-back counts as that night's.
    BackupRecords::run(['kind' => BackupRunKind::ReadBack, 'state' => BackupRunState::Failed, 'started_at' => ($this->at)('2026-10-18 01:21'), 'ended_at' => ($this->at)('2026-10-18 01:30')]);

    expect(($this->due)()->readBackDue($now))->toBeFalse()
        // The next night, not before its backup has started.
        ->and(($this->due)()->readBackDue(($this->at)('2026-10-19 01:10')))->toBeFalse();

    ($this->backupAt)('2026-10-19 01:00:30');

    expect(($this->due)()->readBackDue(($this->at)('2026-10-19 01:30')))->toBeTrue();

    $this->place->keys()->delete();

    expect(($this->due)()->readBackDue(($this->at)('2026-10-19 01:30')))->toBeFalse();
});

it('is not due to read back while no backup has ever finished, however many have started', function (): void {
    $now = ($this->at)('2026-10-18 01:05');

    BackupRecords::run(['state' => BackupRunState::Running, 'started_at' => ($this->at)('2026-10-18 01:00:30'), 'ended_at' => null]);

    expect(($this->due)()->readBackDue($now))->toBeFalse();

    BackupRecords::run(['state' => BackupRunState::Failed, 'started_at' => ($this->at)('2026-10-18 01:01'), 'ended_at' => ($this->at)('2026-10-18 01:02')]);

    expect(($this->due)()->readBackDue($now))->toBeFalse();

    // A finished backup of an earlier night, with one started since the hour, makes it due.
    ($this->backupAt)('2026-10-17 01:00:30');

    expect(($this->due)()->readBackDue($now))->toBeTrue();
});

it('is due to test-restore weekly after that night’s backup, only with a spare database named', function (): void {
    ($this->backupAt)('2026-10-18 01:00:30');
    $now = ($this->at)('2026-10-18 03:00');

    expect(($this->due)()->testRestoreDue($now))->toBeFalse();

    config(['database.connections.teme_verify.database' => 'teme_verify']);

    expect(($this->due)()->testRestoreDue($now))->toBeTrue();

    BackupRecords::run(['kind' => BackupRunKind::TestRestore, 'started_at' => ($this->at)('2026-10-12 02:00'), 'ended_at' => ($this->at)('2026-10-12 02:30')]);

    expect(($this->due)()->testRestoreDue($now))->toBeFalse();

    ($this->backupAt)('2026-10-19 01:00:30');

    expect(($this->due)()->testRestoreDue(($this->at)('2026-10-19 03:00')))->toBeTrue();
});
