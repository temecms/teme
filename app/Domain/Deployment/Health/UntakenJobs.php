<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The queues whose jobs no worker is taking (I-3112): a job a worker could
 * take has waited WAIT seconds or more, no job on the queue is held by a
 * worker, and no worker has finished one there for WAIT seconds. "Could
 * take" is the database queue's own test, retry_after and all.
 */
final readonly class UntakenJobs
{
    /** Seconds a job waits, with nothing taking its queue's jobs, before it counts. */
    public const int WAIT = 600;

    /**
     * Each such queue by name, in alphabetical order, with when its
     * longest-waiting job began to wait.
     *
     * @return array<string, CarbonImmutable>
     */
    public function find(): array
    {
        $now = CarbonImmutable::now();
        $retryAfter = $this->retryAfter();
        $cutoff = $now->getTimestamp() - self::WAIT;

        // A job waits from when it became available, or, when a worker held
        // it and stopped, from when its reservation ran out; one held for
        // less than retry_after is a worker at work.
        $rows = DB::select(
            <<<'SQL'
            SELECT waiting.queue, MIN(waiting.since) AS since
            FROM (
                SELECT jobs.queue,
                       CASE WHEN jobs.reserved_at IS NULL THEN jobs.available_at
                            ELSE jobs.reserved_at + CAST(? AS integer) END AS since
                FROM jobs
                WHERE (jobs.reserved_at IS NULL AND jobs.available_at <= CAST(? AS integer))
                   OR jobs.reserved_at <= CAST(? AS integer)
            ) AS waiting
            WHERE NOT EXISTS (
                    SELECT 1 FROM jobs AS held
                    WHERE held.queue = waiting.queue
                      AND held.reserved_at > CAST(? AS integer)
                )
              AND NOT EXISTS (
                    SELECT 1 FROM heartbeats
                    WHERE heartbeats.name = CAST(? AS text) || waiting.queue
                      AND heartbeats.beaten_at > CAST(? AS timestamptz)
                )
            GROUP BY waiting.queue
            ORDER BY waiting.queue
            SQL,
            [
                $retryAfter,
                $cutoff,
                $cutoff - $retryAfter,
                $now->getTimestamp() - $retryAfter,
                Heartbeats::QUEUE_PREFIX,
                Heartbeats::moment($now->subSeconds(self::WAIT)),
            ],
        );

        $untaken = [];

        foreach ($rows as $row) {
            $untaken[(string) $row->queue] = CarbonImmutable::createFromTimestamp((int) $row->since);
        }

        return $untaken;
    }

    /**
     * The longest retry_after among the database queue's connections, all
     * of which share the jobs table, or 90 seconds when none says.
     */
    public function retryAfter(): int
    {
        $longest = 0;

        foreach ((array) config('queue.connections', []) as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'database') {
                $longest = max($longest, (int) ($connection['retry_after'] ?? 0));
            }
        }

        return $longest > 0 ? $longest : 90;
    }
}
