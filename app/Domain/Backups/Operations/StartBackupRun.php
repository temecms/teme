<?php

/**
 * Records that a backup, a prune or one of their steps has started (I-362):
 * one running row, in a short transaction of its own, so the run is seen
 * while it works. Starting a whole backup or prune first ends, as failed,
 * every one still running: those work only under the backup lock, which
 * the caller now holds, so whatever ran them has ended.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Concerns\AuthorizesBackupRuns;
use App\Domain\Backups\Operations\Input\StartBackupRunInput;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Teme\Contracts\Operations\OperationInput;

final class StartBackupRun extends BaseOperation
{
    use AuthorizesBackupRuns;

    /** What a run left by a process that ended says. */
    public const string ABANDONED = 'This stopped before it finished: the server stopped running it.';

    public function __construct(private readonly BackupPlaces $places, private readonly BackupLock $lock) {}

    protected function validate(OperationInput $input): void
    {
        /** @var StartBackupRunInput $input */
        if (($input->startedBy === BackupTrigger::Person) !== ($input->actorId !== null)) {
            throw new InvalidArgumentException('A person’s run names the person, and only a person’s run does.');
        }

        // Nothing backs up on a key nobody holds (I-2401).
        if ($input->kind === BackupRunKind::Backup && $input->parentId === null && ! $this->places->isSetUp()) {
            throw ValidationException::withMessages(['place' => ['Finish setting backups up first.']]);
        }

        // Two installs never back up into one place (docs/architecture/backups.md).
        if ($input->parentId === null && in_array($input->kind, [BackupRunKind::Backup, BackupRunKind::Prune], true) && $this->places->isPaused()) {
            throw ValidationException::withMessages(['place' => [BackupPlaces::PAUSED]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var StartBackupRunInput $input */
        $now = CarbonImmutable::now();

        if ($input->parentId === null && in_array($input->kind, [BackupRunKind::Backup, BackupRunKind::Prune], true)) {
            // Ending runs as abandoned is safe only while this process holds the lock (I-358).
            if (! $this->lock->stillMine()) {
                throw new LogicException('A backup or a prune starts only under the backup lock.');
            }

            $this->endAbandoned($now);
        }

        // A backup's copies and checks run after it has ended, so its state isn't asked.
        if ($input->parentId !== null && ! BackupRun::query()->whereKey($input->parentId)->whereNull('parent_id')->exists()) {
            throw new InvalidArgumentException('A step belongs to a whole run that exists.');
        }

        return BackupRun::query()->create([
            'kind' => $input->kind,
            'state' => BackupRunState::Running,
            'started_by' => $input->startedBy,
            'actor_id' => $input->actorId === null ? null : (int) $input->actorId,
            'destination_id' => $input->destinationId,
            'parent_id' => $input->parentId,
            'figures' => [],
            'started_at' => $now,
        ])->id;
    }

    /**
     * Every backup or prune still running, and every step still running
     * under one, ended as failed. Read-backs, test restores and restores are
     * other parts' runs, and may be working (I-362).
     */
    private function endAbandoned(CarbonImmutable $now): void
    {
        $kinds = [BackupRunKind::Backup->value, BackupRunKind::Prune->value];

        DB::update(<<<'SQL'
            UPDATE backup_runs
            SET state = ?, ended_at = greatest(?::timestamptz, started_at), message = ?
            WHERE state = ?
              AND (
                  kind IN (?, ?)
                  OR parent_id IN (SELECT id FROM backup_runs WHERE parent_id IS NULL AND kind IN (?, ?))
              )
            SQL, [
            BackupRunState::Failed->value,
            $now->format('Y-m-d H:i:s.uP'),
            self::ABANDONED,
            BackupRunState::Running->value,
            ...$kinds,
            ...$kinds,
        ]);
    }
}
