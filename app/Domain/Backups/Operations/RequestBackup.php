<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\Operations\Concerns\AuthorizesBackupRuns;
use App\Domain\Backups\Operations\Input\RequestBackupInput;
use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Tracking\BackupJobKind;
use App\Domain\Backups\Tracking\TrackedBackup;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Back up now (I-361): puts a backup in line, as a tracked job that waits
 * for the scheduler, which runs it within a minute as whoever asked. One
 * waits at a time; a backup, a prune or a check running meanwhile only
 * makes it wait longer. Returns the job's ID.
 */
final class RequestBackup extends BaseOperation
{
    use AuthorizesBackupRuns;

    public const string WAITING = 'A backup is already waiting to start. It starts within a minute.';

    /** The advisory lock that puts one request in line at a time. */
    public const string LOCK = 'teme.backup.request';

    public function __construct(
        private readonly BackupPlaces $places,
        private readonly BackupJobs $jobs,
        private readonly StartJob $start,
    ) {}

    protected function validate(OperationInput $input): void
    {
        // Nothing backs up on a key nobody holds (I-2401).
        if (! $this->places->isSetUp()) {
            throw ValidationException::withMessages(['place' => ['Finish setting backups up first.']]);
        }

        if ($this->places->isPaused()) {
            throw ValidationException::withMessages(['place' => [BackupPlaces::PAUSED]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RequestBackupInput $input */
        // One request at a time, from every server, until this transaction ends.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);

        if ($this->jobs->waiting()) {
            throw ValidationException::withMessages(['job' => [self::WAITING]]);
        }

        return (string) $this->start->execute(new StartJobInput(
            BackupJobKind::KEY,
            TrackedBackup::BACK_UP_NOW,
            runType: BackupJobKind::KEY,
            runId: BackupJobs::RUN_ID,
            actorId: $input->actorId(),
        ));
    }
}
