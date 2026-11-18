<?php

/**
 * The lock a backup holds from its database snapshot to its last media file
 * (I-358): a session-level PostgreSQL advisory lock, seen alike by the web,
 * the queue worker, the scheduler and teme:deploy, and never emptied by
 * cache:clear. It is held on a connection of its own, let go at once when
 * the work ends, and that connection closed after, so no transaction the
 * work leaves broken can strand it. A file deletion asks isHeld() and waits
 * while it is.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class BackupLock
{
    /** The lock's name, which PostgreSQL's hashtext() turns into its key. */
    public const string KEY = 'teme.backup';

    /** The connection the lock is held on, made when it is taken. */
    public const string CONNECTION = 'teme_backup_lock';

    private bool $holding = false;

    /**
     * Runs $work holding the lock, and lets go when the work ends or throws.
     * Never waits: while another session holds it, a backup is refused.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     *
     * @return T
     *
     * @throws BackupAlreadyRunning
     */
    public function during(Closure $work): mixed
    {
        if ($this->holding) {
            throw new LogicException('This process already holds the backup lock.');
        }

        try {
            if (! (bool) $this->session()->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS taken', [self::KEY])->taken) {
                throw new BackupAlreadyRunning('A backup is already running. Try again when it has finished.');
            }

            $this->holding = true;

            return $work();
        } finally {
            $this->holding = false;

            // Let go at once: a closed session's locks go only when its server
            // process ends, which another session can see late (I-358).
            try {
                $session = DB::connection(self::CONNECTION);

                // A transaction the work left broken refuses every statement, the unlock among them.
                if ($session->transactionLevel() > 0) {
                    $session->rollBack(0);
                }

                $session->select('SELECT pg_advisory_unlock_all()');
            } catch (Throwable) {
                // A session the work broke can't ask; closing it lets go instead.
            }

            DB::purge(self::CONNECTION);
        }
    }

    /**
     * Whether a backup holds the lock now, in this process or any other.
     * Asking takes the shared lock for an instant and lets go, so it never
     * holds anything (I-358).
     */
    public function isHeld(): bool
    {
        $free = (bool) DB::selectOne('SELECT pg_try_advisory_lock_shared(hashtext(?)) AS free', [self::KEY])->free;

        if ($free) {
            DB::select('SELECT pg_advisory_unlock_shared(hashtext(?))', [self::KEY]);
        }

        return ! $free;
    }

    /**
     * Whether this process still holds the lock: it took it with during(),
     * and the lock's own session is alive and still has it, as PostgreSQL
     * lists it for that session's backend. False on any connection error,
     * as when the session was closed or the server restarted under a run
     * (I-358).
     */
    public function stillMine(): bool
    {
        if (! $this->holding) {
            return false;
        }

        try {
            // The lock's session takes this one advisory lock and nothing else.
            return (bool) DB::connection(self::CONNECTION)->selectOne(
                "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted) AS mine",
            )->mine;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A new session on the database the default connection names now.
     */
    private function session(): ConnectionInterface
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();

        return DB::connectUsing(self::CONNECTION, [...$config, 'name' => self::CONNECTION], force: true);
    }
}
