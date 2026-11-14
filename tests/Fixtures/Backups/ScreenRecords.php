<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Places, keys and runs written straight to the tables, as Tools › Backups
 * reads them, without restic: the screen's tests seed what 1.12d's runs and
 * 1.12e's checks record.
 */
final class ScreenRecords
{
    /**
     * Stores each group's overrides and forgets the cached copy.
     *
     * @param  array<string, array<string, mixed>>  $groups
     */
    public static function settings(array $groups): void
    {
        foreach ($groups as $group => $overrides) {
            SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
            Cache::memo()->forget(SettingsRepository::cacheKey($group));
        }
    }

    /**
     * A place in use with its machine key and, unless $recoveryLabel is null, a recovery key recorded.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function place(string $name = 'This server', array $overrides = [], ?string $recoveryLabel = 'Recovery key'): BackupDestination
    {
        $place = BackupRecords::destination(['name' => $name, ...$overrides]);
        BackupRecords::key($place);

        if ($recoveryLabel !== null) {
            BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => $recoveryLabel]);
        }

        return $place;
    }

    /**
     * A whole backup taken by $place, started at $utc and ended five minutes later (or still running).
     *
     * @param  array<string, int>  $figures
     */
    public static function backup(BackupDestination $place, string $utc, BackupRunState $state = BackupRunState::Finished, array $figures = [], ?string $message = null): BackupRun
    {
        $started = CarbonImmutable::parse($utc, 'UTC');

        return BackupRecords::run([
            'kind' => BackupRunKind::Backup,
            'state' => $state,
            'started_by' => BackupTrigger::Schedule,
            'destination_id' => $place->id,
            'figures' => $figures,
            'message' => $message,
            'started_at' => $started,
            'ended_at' => $state === BackupRunState::Running ? null : $started->addMinutes(5),
        ]);
    }

    /**
     * A run of a check kind at $place (or none), under $parent when given.
     */
    public static function check(BackupRunKind $kind, ?BackupDestination $place, string $utc, bool $passed = true, ?string $message = null, ?BackupRun $parent = null): BackupRun
    {
        $started = CarbonImmutable::parse($utc, 'UTC');

        return BackupRecords::run([
            'kind' => $kind,
            'state' => $passed ? BackupRunState::Finished : BackupRunState::Failed,
            'started_by' => BackupTrigger::Schedule,
            'destination_id' => $place?->id,
            'parent_id' => $parent?->id,
            'figures' => ['seconds' => 30],
            'message' => $message,
            'started_at' => $started,
            'ended_at' => $started->addMinute(),
        ]);
    }
}
