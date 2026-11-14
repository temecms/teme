<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * When the scheduler and each queue's workers last showed they were alive
 * (I-3112): one row a name, overwritten in place, never added to. A
 * telemetry sink, read only by the Status banners and the doctor.
 */
final readonly class Heartbeats
{
    public const string SCHEDULER = 'scheduler';

    /** The updater, which carries out an update asked for from the admin (I-3326). */
    public const string UPDATER = 'updater';

    /** A queue's row is named this, then the queue's name. */
    public const string QUEUE_PREFIX = 'queue:';

    /**
     * Notes that $name is alive now. A row beaten under ten seconds ago is
     * left as it is, so a busy queue rewrites its row at most that often.
     * A beat that can't be written is dropped: this never throws.
     */
    public function beat(string $name): void
    {
        try {
            DB::statement(
                <<<'SQL'
                INSERT INTO heartbeats (name, beaten_at) VALUES (?, CAST(? AS timestamptz))
                ON CONFLICT (name) DO UPDATE SET beaten_at = EXCLUDED.beaten_at
                WHERE heartbeats.beaten_at < EXCLUDED.beaten_at - interval '10 seconds'
                SQL,
                [$name, self::moment(CarbonImmutable::now())],
            );
        } catch (QueryException|PDOException) {
            // Dropped, as a sink's line is: a throw from the worker's listener
            // would fail the job it has just finished (docs/architecture/operations.md).
            // PDOException: a reconnect after a refused connection throws it unwrapped.
        }
    }

    /**
     * When $name last beat, or null when it never has.
     */
    public function lastBeat(string $name): ?CarbonImmutable
    {
        $at = DB::table('heartbeats')->where('name', $name)->value('beaten_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * When a worker last finished a job on each queue, by the queue's name,
     * in alphabetical order.
     *
     * @return array<string, CarbonImmutable>
     */
    public function queues(): array
    {
        $beats = [];
        $rows = DB::table('heartbeats')->where('name', 'like', self::QUEUE_PREFIX.'%')->orderBy('name')->get(['name', 'beaten_at']);

        foreach ($rows as $row) {
            $beats[substr((string) $row->name, strlen(self::QUEUE_PREFIX))] = CarbonImmutable::parse((string) $row->beaten_at);
        }

        return $beats;
    }

    /**
     * A time as PostgreSQL reads it without guessing: microseconds and the
     * offset, whatever the session's time zone.
     */
    public static function moment(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }
}
