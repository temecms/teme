<?php

/**
 * A backup as a kind of tracked job (I-104). Nothing is queued: a waiting
 * backup job is taken by the scheduler within a minute and runs as whoever
 * started it, so retrying only leaves it waiting, or refuses at once, in
 * words; cancelling drops nothing, since a running backup stops at its next
 * step's report.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tracking;

use App\Domain\Backups\Operations\RequestBackup;
use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Users\Actors;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BackupJobKind implements JobKind
{
    /** The kind's key, which is also the run type its jobs carry. */
    public const string KEY = 'backup';

    public const string GONE = 'The person who asked for this backup no longer has an account, so it can’t run again. Press Back up now instead.';

    public const string ANOTHER = 'A backup is already waiting to start, so this one wasn’t retried.';

    public const string NOT_ALLOWED = 'Only an administrator manages backups.';

    public function __construct(
        private BackupPlaces $places,
        private BackupJobs $jobs,
        private FailJob $fail,
        private Actors $actors,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        // The scheduler runs it as its starter, the nightly one as the operator,
        // so only someone who may back up asks for it again (I-392).
        $refusal = DB::transaction(function () use ($job, $actorId): ?string {
            // One backup waits at a time, as RequestBackup keeps it: the same lock.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [RequestBackup::LOCK]);

            $refusal = match (true) {
                $actorId !== null && $this->actors->find($actorId)?->can('backups.manage') !== true => self::NOT_ALLOWED,
                ! $job->byOperator && $job->startedBy === null => self::GONE,
                ! $this->places->isSetUp() => 'Finish setting backups up first.',
                $this->places->isPaused() => BackupPlaces::PAUSED,
                $this->jobs->waiting(except: $job->id) => self::ANOTHER,
                default => null,
            };

            // Back to failed, as before Retry, in these words, before the lock is let go.
            if ($refusal !== null) {
                $this->fail->execute(new FailJobInput($job->id, $refusal, $actorId));
            }

            return $refusal;
        });

        if ($refusal !== null) {
            throw ValidationException::withMessages(['job' => $refusal]);
        }
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        // Nothing is queued: a waiting job is never taken, and a running one stops at its next report (I-104).
    }
}
