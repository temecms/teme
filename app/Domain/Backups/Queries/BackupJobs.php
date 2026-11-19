<?php

/**
 * The backups' tracked jobs (I-104), all of the run `backup`/`site`: which
 * waits next, which a dead process left running, and the one Tools › Backups
 * shows, as Tools › Jobs draws it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\Tracking\BackupJobKind;
use App\Domain\Jobs\Queries\TrackedJobList;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type JobRow from TrackedJobList
 */
final readonly class BackupJobs
{
    /** The run every backup job belongs to. */
    public const string RUN_ID = 'site';

    public function __construct(private TrackedJobList $list) {}

    /**
     * Whether a backup job is waiting, other than $except.
     */
    public function waiting(?string $except = null): bool
    {
        $query = $this->jobs()->where('state', 'waiting');

        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        return $query->exists();
    }

    /**
     * Returns the ID of the waiting backup job created first, or null when
     * none waits.
     */
    public function nextWaiting(): ?string
    {
        $id = $this->jobs()->where('state', 'waiting')->orderBy('created_at')->orderBy('id')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Returns the IDs of every running backup job but $except.
     *
     * @return list<string>
     */
    public function leftRunning(string $except): array
    {
        return $this->jobs()
            ->where('state', 'running')
            ->where('id', '!=', $except)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Returns the backup job Tools › Backups shows, as Tools › Jobs draws it:
     * the open (waiting or running) one created first, since a retried job
     * keeps its created_at and a newer finished one mustn't hide it; with
     * none open, the newest; or null when there is no backup job.
     *
     * @return JobRow|null
     */
    public function current(): ?array
    {
        $open = $this->jobs()
            ->whereIn('state', ['waiting', 'running'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');

        if ($open !== null) {
            return $this->list->one((string) $open);
        }

        return $this->list->ofRun(BackupJobKind::KEY, self::RUN_ID, 1)[0] ?? null;
    }

    /**
     * Returns a job's label, or an empty string when there is none.
     */
    public function label(string $id): string
    {
        return (string) DB::table('tracked_jobs')->where('id', $id)->value('label');
    }

    /**
     * Returns a job's message, or null when it has none.
     */
    public function message(string $id): ?string
    {
        $message = DB::table('tracked_jobs')->where('id', $id)->value('message');

        return $message === null ? null : (string) $message;
    }

    private function jobs(): Builder
    {
        return DB::table('tracked_jobs')->where('run_type', BackupJobKind::KEY)->where('run_id', self::RUN_ID);
    }
}
