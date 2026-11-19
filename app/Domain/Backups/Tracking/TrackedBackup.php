<?php

/**
 * Runs a backup as a tracked job (I-104), only where the operator is present:
 * the scheduler. The backup runs as whoever started the job, a person by
 * their ID or the schedule as the operator; the job's own reports are the
 * operator's, whose process this is. Each step is reported as it starts, a
 * cancel stops it before the next, and the job ends in the backup's own words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tracking;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupRefused;
use App\Domain\Backups\BackupResult;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\BackupWords;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\Queries\BackupJobs;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Closure;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final readonly class TrackedBackup
{
    public const string BACK_UP_NOW = 'Back up now';

    public const string NIGHTLY = 'Nightly backup';

    public const string GONE = 'The person who asked for this backup no longer has an account, so it didn’t run.';

    public const string NOT_ALLOWED = 'The person who asked for this backup no longer manages backups, so it didn’t run.';

    public const string UNEXPECTED = 'The backup stopped on an error Teme didn’t expect. The log has its details.';

    public const string ENDED = 'Its job had ended, so it stopped before it finished.';

    public function __construct(
        private BackupSite $site,
        private BackupPlaces $places,
        private BackupJobs $jobs,
        private StartJob $start,
        private ReportJobProgress $report,
        private FinishJob $finish,
        private FailJob $fail,
        private Actors $actors,
    ) {}

    /**
     * Starts the nightly backup's job, as the operator, waiting; its ID.
     */
    public function nightly(): string
    {
        self::ensureOperator();

        return (string) $this->start->execute(new StartJobInput(BackupJobKind::KEY, self::NIGHTLY, runType: BackupJobKind::KEY, runId: BackupJobs::RUN_ID));
    }

    /**
     * Runs the waiting backup job $jobId as its starter and ends the job:
     * the backup's result, or null when it didn't start (no longer waiting,
     * left as it is; or refused, the job then failed, in words). While
     * another process holds the backup lock, BackupAlreadyRunning leaves the
     * job waiting.
     *
     * @param  (Closure(string): void)|null  $say  told each step once it's reported
     *
     * @throws BackupAlreadyRunning
     */
    public function run(string $jobId, ?Closure $say = null): ?BackupResult
    {
        self::ensureOperator();
        $job = TrackedJob::query()->findOrFail($jobId);

        // Read afresh: a job cancelled since it was picked is left as it is.
        if ($job->state !== JobState::Waiting) {
            return null;
        }

        $byPerson = $job->starter === 'person';
        $actorId = $byPerson && $job->started_by !== null ? (string) $job->started_by : null;

        // Checked before anything is written, so these refusals leave no run (I-392).
        if ($byPerson) {
            $person = $this->actors->find($actorId);

            if ($person === null || ! $person->can('backups.manage')) {
                $this->end($jobId, $person === null ? self::GONE : self::NOT_ALLOWED, false);

                return null;
            }
        }

        // A retried job carries its count on (I-104).
        $total = $job->done + BackupSite::steps($this->places->inUse()->count());
        $reported = false;

        $progress = function (string $step) use ($jobId, $total, $say, &$reported): void {
            // The first step runs under the backup lock, so no other backup is running (I-358).
            if (! $reported) {
                foreach ($this->jobs->leftRunning($jobId) as $left) {
                    $this->fail->execute(new FailJobInput($left, StartBackupRun::ABANDONED));
                }
            }

            // As the operator, so a starter deleted mid-run can't make the report refuse.
            $going = $this->report->execute(new ReportJobProgressInput($jobId, $reported ? 1 : 0, $total, $step));
            $reported = true;

            // Cancelled, or ended some other way: stop before this step. At the
            // first step the run is already written, so it fails, and alerts (I-2409).
            if (! $going) {
                throw new BackupRefused($this->stoppedWords($jobId));
            }

            if ($say !== null) {
                $say($step);
            }
        };

        try {
            $result = $this->site->run($byPerson ? BackupTrigger::Person : BackupTrigger::Schedule, $actorId, $progress);
        } catch (BackupAlreadyRunning $running) {
            throw $running;
        } catch (Throwable $unexpected) {
            // StartBackupRun refuses a site not set up, or paused, before it writes anything.
            if ($unexpected instanceof ValidationException) {
                $this->end($jobId, (string) collect($unexpected->errors())->flatten()->first(), false);

                return null;
            }

            // A starter demoted or deleted mid-run makes EndBackupRun refuse; the
            // next backup's StartBackupRun ends that run as abandoned (I-358).
            $this->end($jobId, self::UNEXPECTED, false);

            throw $unexpected;
        }

        if ($result->allWell()) {
            $this->end($jobId, self::finishedWords($result, $this->others($result)), true);
        } else {
            $this->end($jobId, (string) ($result->sentence ?? $result->problems[0] ?? self::UNEXPECTED), false);
        }

        return $result;
    }

    /**
     * What a finished backup holds and where, as its job's closing words.
     *
     * @param  list<string>  $others  the places it was copied to
     */
    public static function finishedWords(BackupResult $result, array $others): string
    {
        $held = match ($result->mediaFiles) {
            0 => 'The database is',
            1 => 'The database and 1 media file are',
            default => 'The database and '.number_format($result->mediaFiles).' media files are',
        };
        $where = 'at '.$result->place.($others === [] ? '' : ' and copied to '.BackupWords::names($others));

        return "{$held} backed up {$where}, and checked.";
    }

    /**
     * The names of the places in use other than the one that took the backup.
     *
     * @return list<string>
     */
    private function others(BackupResult $result): array
    {
        return $this->places->inUse()
            ->reject(fn (BackupDestination $place): bool => $place->name === $result->place)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Why the run stops: the cancel's own words (I-104), or ENDED.
     */
    private function stoppedWords(string $jobId): string
    {
        $job = TrackedJob::query()->find($jobId);

        return $job !== null && $job->state === JobState::Cancelled && is_string($job->message) && $job->message !== ''
            ? rtrim($job->message, '.').' before it finished.'
            : self::ENDED;
    }

    /**
     * Ends the job in these words, at FailJob's and FinishJob's limit, as the
     * operator; a job that has ended already stays as it ended. The inbox
     * still tells its starter, whom FinishJob and FailJob address.
     */
    private function end(string $jobId, string $words, bool $finished): void
    {
        $words = mb_strimwidth($words, 0, 500, '…');

        if ($finished) {
            $this->finish->execute(new FinishJobInput($jobId, $words));
        } else {
            $this->fail->execute(new FailJobInput($jobId, $words));
        }
    }

    /**
     * @throws LogicException where the operator isn't present: a request or a queue worker
     */
    private static function ensureOperator(): void
    {
        if (! Operator::isPresent()) {
            throw new LogicException('Backups are run by the scheduler or a teme: command, where the operator is present.');
        }
    }
}
