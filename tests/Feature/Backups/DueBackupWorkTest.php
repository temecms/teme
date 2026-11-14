<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Queries\DueBackupWork;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Backups\BackupRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'Africa/Lagos'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    $this->place = BackupRecords::destination();
    BackupRecords::key($this->place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
    $this->due = fn (): DueBackupWork => app(DueBackupWork::class);
    // 02:00 in Lagos is 01:00 UTC.
    $this->at = fn (string $utc): CarbonImmutable => CarbonImmutable::parse($utc, 'UTC');
});

it('is due from the hour, in the site’s time zone, until a backup starts', function (): void {
    BackupRecords::run(['started_at' => ($this->at)('2026-10-17 01:05'), 'ended_at' => ($this->at)('2026-10-17 01:20')]);

    expect(($this->due)()->slot(($this->at)('2026-10-18 00:30'))->toIso8601String())->toBe('2026-10-17T01:00:00+00:00')
        ->and(($this->due)()->backupDue(($this->at)('2026-10-18 00:30')))->toBeFalse()
        ->and(($this->due)()->slot(($this->at)('2026-10-18 01:00'))->toIso8601String())->toBe('2026-10-18T01:00:00+00:00')
        ->and(($this->due)()->backupDue(($this->at)('2026-10-18 01:00')))->toBeTrue();

    BackupRecords::run(['state' => BackupRunState::Running, 'started_at' => ($this->at)('2026-10-18 01:00:30'), 'ended_at' => null]);

    expect(($this->due)()->backupDue(($this->at)('2026-10-18 01:01')))->toBeFalse();
});

it('backs up when the server is back, and counts a failed or abandoned backup as that night’s', function (): void {
    BackupRecords::run(['started_at' => ($this->at)('2026-10-14 01:00'), 'ended_at' => ($this->at)('2026-10-14 01:10')]);

    // Off since the 14th: due at once, whatever the hour.
    expect(($this->due)()->backupDue(($this->at)('2026-10-18 15:42')))->toBeTrue();

    BackupRecords::run(['state' => BackupRunState::Failed, 'message' => 'This stopped before it finished: the process running it ended.', 'started_at' => ($this->at)('2026-10-18 15:43'), 'ended_at' => ($this->at)('2026-10-18 15:44')]);

    expect(($this->due)()->backupDue(($this->at)('2026-10-18 15:45')))->toBeFalse();
});

it('is never due before backups are set up', function (): void {
    $this->place->keys()->delete();

    expect(($this->due)()->backupDue(($this->at)('2026-10-18 01:00')))->toBeFalse()
        ->and(($this->due)()->pruneDue(($this->at)('2026-10-18 01:00')))->toBeFalse();
});

it('is due to prune after that night’s backup has started, once in seven days', function (): void {
    expect(($this->due)()->pruneDue(($this->at)('2026-10-18 01:30')))->toBeFalse();

    BackupRecords::run(['started_at' => ($this->at)('2026-10-18 01:00'), 'ended_at' => ($this->at)('2026-10-18 01:10')]);

    expect(($this->due)()->pruneDue(($this->at)('2026-10-18 01:30')))->toBeTrue();

    $prune = BackupRecords::run(['kind' => BackupRunKind::Prune, 'started_at' => ($this->at)('2026-10-12 01:15'), 'ended_at' => ($this->at)('2026-10-12 01:20')]);

    expect(($this->due)()->pruneDue(($this->at)('2026-10-18 01:30')))->toBeFalse();

    $prune->forceFill(['started_at' => ($this->at)('2026-10-10 01:15'), 'ended_at' => ($this->at)('2026-10-10 01:20')])->save();

    expect(($this->due)()->pruneDue(($this->at)('2026-10-18 01:30')))->toBeTrue();
});
