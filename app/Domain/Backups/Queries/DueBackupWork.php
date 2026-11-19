<?php

/**
 * What the schedule should start now (I-361), read from the settings and
 * backup_runs alone: the nightly backup once its hour has come in the
 * site's time zone and none has started since, and the weekly prune after
 * that night's backup. A server that was off at the hour backs up when it's
 * back, and a changed hour needs no schedule rebuilt.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Teme\Contracts\Settings\SettingsReader;

final readonly class DueBackupWork
{
    /** Days between prunes. */
    public const int PRUNE_DAYS = 7;

    public function __construct(private BackupPlaces $places, private SettingsReader $settings) {}

    /**
     * The latest time the nightly backup's hour came, at or before $now, in
     * the site's time zone: today's, or yesterday's before the hour. In UTC.
     */
    public function slot(CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone($this->settings->string('general', 'timezone'));
        $hour = (int) Str::before($this->settings->string(BackupSettings::GROUP, 'hour'), ':');
        $today = $local->setTime($hour, 0);

        return ($today->greaterThan($local) ? $today->subDay() : $today)->utc();
    }

    /**
     * Whether the nightly backup is due: backups are set up, nothing is paused,
     * and no backup has started since the slot. A failed or abandoned one counts, so a
     * backup that crashes isn't started again every minute.
     */
    public function backupDue(CarbonImmutable $now): bool
    {
        return $this->places->isSetUp() && ! $this->places->isPaused() && ! $this->startedSince(BackupRunKind::Backup, $this->slot($now));
    }

    /**
     * Whether the weekly prune is due: backups are set up, nothing is paused, that night's
     * backup has started, and no prune has in the last seven days.
     */
    public function pruneDue(CarbonImmutable $now): bool
    {
        return $this->places->isSetUp()
            && ! $this->places->isPaused()
            && $this->startedSince(BackupRunKind::Backup, $this->slot($now))
            && ! $this->startedSince(BackupRunKind::Prune, $now->subDays(self::PRUNE_DAYS));
    }

    private function startedSince(BackupRunKind $kind, CarbonImmutable $moment): bool
    {
        // backup_runs_kind_index: (kind, started_at DESC).
        return BackupRun::query()
            ->where('kind', $kind->value)
            ->whereNull('parent_id')
            ->where('started_at', '>=', $moment)
            ->exists();
    }
}
