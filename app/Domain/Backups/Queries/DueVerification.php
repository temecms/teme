<?php

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Verify\SpareDatabase;
use Carbon\CarbonImmutable;

/**
 * When the checks of the backups are due (I-362), read from backup_runs and
 * the schedule alone, and which seventh of a place's data its next
 * read-back reads.
 */
final readonly class DueVerification
{
    /** Days between test restores. */
    public const int TEST_RESTORE_DAYS = 7;

    public function __construct(private DueBackupWork $backups, private BackupPlaces $places) {}

    /**
     * Whether the nightly read-back is due: backups are set up and nothing is paused, that night's
     * backup has started, some whole backup has finished at some time (a new
     * site has nothing to read back yet), and no read-back has started since.
     * A failed or abandoned read-back counts, so a crashing check isn't
     * started again every minute.
     */
    public function readBackDue(CarbonImmutable $now): bool
    {
        $slot = $this->backups->slot($now);

        return $this->places->isSetUp()
            && ! $this->places->isPaused()
            && $this->startedSince(BackupRunKind::Backup, $slot)
            && $this->aBackupHasFinished()
            && ! $this->startedSince(BackupRunKind::ReadBack, $slot);
    }

    private function aBackupHasFinished(): bool
    {
        // backup_runs_kind_index: (kind, started_at DESC).
        return BackupRun::query()
            ->where('kind', BackupRunKind::Backup->value)
            ->whereNull('parent_id')
            ->where('state', BackupRunState::Finished->value)
            ->exists();
    }

    /**
     * Whether the weekly test restore is due: a spare database is named
     * (I-2406), backups are set up and nothing is paused, that night's backup has started, and no
     * test restore has in seven days.
     */
    public function testRestoreDue(CarbonImmutable $now): bool
    {
        return SpareDatabase::isNamed()
            && $this->places->isSetUp()
            && ! $this->places->isPaused()
            && $this->startedSince(BackupRunKind::Backup, $this->backups->slot($now))
            && ! $this->startedSince(BackupRunKind::TestRestore, $now->subDays(self::TEST_RESTORE_DAYS));
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

    /**
     * The seventh a place's next read-back reads, 1 to 7: the one after its
     * newest finished read-back's, or 1 when it has none. A seventh that
     * failed is read again, since only finished ones move it on.
     */
    public function nextSeventh(string $placeId): int
    {
        // backup_runs_destination_index: (destination_id, kind, started_at DESC).
        $figures = BackupRun::query()
            ->where('destination_id', $placeId)
            ->where('kind', BackupRunKind::ReadBack->value)
            ->whereNotNull('parent_id')
            ->where('state', BackupRunState::Finished->value)
            ->orderByDesc('started_at')
            ->value('figures');
        $last = is_array($figures) && is_int($figures['subset'] ?? null) ? $figures['subset'] : 0;

        return $last >= 1 && $last < 7 ? $last + 1 : 1;
    }
}
