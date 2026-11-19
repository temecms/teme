<?php

/**
 * Settles a restored database (docs/architecture/backups.md, Restoring): the
 * run that made the backup finished, whatever else the old server had
 * running ended, every place paused until a person says whether this
 * server is now the site, the restore's own run written, and the restore's
 * start logged again, since the log it was first written in is gone.
 * Returns the restore's run ID.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Concerns\AuthorizesRestores;
use App\Domain\Backups\Operations\Input\SettleRestoredSiteInput;
use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Tracking\BackupJobKind;
use App\Domain\Jobs\JobState;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

final class SettleRestoredSite extends BaseOperation
{
    use AuthorizesRestores;

    public const string UNKNOWN = 'This was still running when this backup was made, so how it ended isn’t known.';

    public const string RESTORED_FROM = 'This backup was made, and the site was restored from it.';

    public const string NEVER_RAN = 'This was waiting when this backup was made, so it never ran.';

    public function __construct(private readonly SecurityLog $log) {}

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): string
    {
        /** @var SettleRestoredSiteInput $input */
        $madeAt = $input->madeAt->utc()->format('Y-m-d H:i:s.uP');

        // Its row was written before the moment its dump saw, so it says running (I-358).
        DB::update(<<<'SQL'
            UPDATE backup_runs
            SET state = ?, ended_at = greatest(?::timestamptz, started_at), snapshot_id = COALESCE(snapshot_id, ?), message = NULL
            WHERE id = ? AND state = ?
            SQL, [BackupRunState::Finished->value, $madeAt, $input->manifestSnapshotId, $input->sourceRunId, BackupRunState::Running->value]);

        DB::update(<<<'SQL'
            UPDATE backup_runs
            SET state = ?, ended_at = greatest(?::timestamptz, started_at), message = ?
            WHERE state = ?
            SQL, [BackupRunState::Failed->value, $madeAt, self::UNKNOWN, BackupRunState::Running->value]);

        // The old server's tracked jobs end as its runs do, telling no one: their work came before the backup (I-1714).
        // The dump is taken under the backup lock, so the one backup job running then made this backup.
        DB::update(<<<'SQL'
            UPDATE tracked_jobs
            SET state = ?, done = COALESCE(total, done), ended_at = greatest(?::timestamptz, created_at), message = ?, updated_at = now()
            WHERE run_type = ? AND run_id = ? AND state = ?
            SQL, [JobState::Finished->value, $madeAt, self::RESTORED_FROM, BackupJobKind::KEY, BackupJobs::RUN_ID, JobState::Running->value]);

        DB::update(<<<'SQL'
            UPDATE tracked_jobs
            SET state = ?, ended_at = greatest(?::timestamptz, created_at), message = ?, updated_at = now()
            WHERE state = ?
            SQL, [JobState::Failed->value, $madeAt, self::NEVER_RAN, JobState::Waiting->value]);

        DB::update(<<<'SQL'
            UPDATE tracked_jobs
            SET state = ?, ended_at = greatest(?::timestamptz, created_at), message = ?, updated_at = now()
            WHERE state = ?
            SQL, [JobState::Failed->value, $madeAt, self::UNKNOWN, JobState::Running->value]);

        // Two installs never back up into one place (docs/architecture/backups.md).
        BackupDestination::query()->where('state', '<>', BackupDestinationState::Removed->value)->update(['paused_at' => CarbonImmutable::now()]);

        $placeId = $input->placeRepositoryId === null ? null : BackupDestination::query()
            ->where('repository_id', $input->placeRepositoryId)
            ->where('state', '<>', BackupDestinationState::Removed->value)
            ->value('id');

        $restoreId = BackupRun::query()->create([
            'kind' => BackupRunKind::Restore,
            'state' => BackupRunState::Running,
            'started_by' => BackupTrigger::Command,
            'actor_id' => null,
            'destination_id' => $placeId,
            'figures' => [],
            'started_at' => $input->startedAt,
        ])->id;

        $this->log->record(SecurityEventType::BackupRestoreStarted, $input->actorId, context: [
            'run' => $input->sourceRunId,
            'place' => $input->place,
            'made_at' => $input->madeAt->utc()->toIso8601String(),
            'safety_run' => $input->safetyRunId,
            'restore' => $restoreId,
        ]);

        return $restoreId;
    }
}
