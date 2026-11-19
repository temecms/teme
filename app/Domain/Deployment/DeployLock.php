<?php

/**
 * The lock one teme:deploy holds while it changes the site (I-377): a
 * session-level PostgreSQL advisory lock on a connection of its own, which
 * a second deploy waits for, and which PostgreSQL lets go of the moment its
 * session ends. It works on a new database, before any table exists.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeployLock
{
    /** The lock's name, which PostgreSQL's hashtext() turns into its key. */
    public const string KEY = 'teme.deploy';

    /** The connection the lock is held on, made when it is taken. */
    public const string CONNECTION = 'teme_deploy_lock';

    /** PostgreSQL's lock_not_available, raised when lock_timeout runs out. */
    private const string TIMED_OUT = '55P03';

    private bool $holding = false;

    /**
     * Runs $work holding the lock: at once when it is free; otherwise after
     * telling $waiting once, as soon as the deploy holding it lets go, or,
     * with $waitSeconds above 0, no later than that. $work is given how many
     * seconds this waited, or null when it didn't.
     *
     * @template T
     *
     * @param  Closure(): void  $waiting
     * @param  Closure(?float): T  $work
     *
     * @return T
     *
     * @throws DeployBusy when the lock wasn't free within $waitSeconds
     */
    public function during(int $waitSeconds, Closure $waiting, Closure $work): mixed
    {
        $session = $this->session();

        try {
            $waited = null;
            // A server's statement_timeout must never cancel the wait, or the session that holds the lock.
            $session->select("SELECT set_config('statement_timeout', '0', false)");

            if (! (bool) $session->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS taken', [self::KEY])->taken) {
                $waiting();
                $started = microtime(true);
                // 0 turns lock_timeout off, so the wait lasts as long as the other deploy does.
                $session->select("SELECT set_config('lock_timeout', ?, false)", [$waitSeconds > 0 ? "{$waitSeconds}s" : '0']);

                try {
                    $session->select('SELECT pg_advisory_lock(hashtext(?))', [self::KEY]);
                } catch (QueryException $notTaken) {
                    if ((string) $notTaken->getCode() === self::TIMED_OUT) {
                        throw DeployBusy::lock($waitSeconds);
                    }

                    throw $notTaken;
                }

                $waited = round(microtime(true) - $started, 1);
            }

            $this->holding = true;

            return $work($waited);
        } finally {
            $this->holding = false;

            // Let go at once, as the backup lock does (I-358).
            try {
                $session->select('SELECT pg_advisory_unlock_all()');
            } catch (Throwable) {
                // A session that can't ask lets go when it closes, below.
            }

            DB::purge(self::CONNECTION);
        }
    }

    /**
     * Whether this process still holds the lock: inside during(), and the
     * lock's own session is alive and lists its advisory lock as granted.
     * False on any connection error, as after a database restart or a
     * killed backend, when another deploy may already hold it.
     */
    public function stillHeld(): bool
    {
        if (! $this->holding) {
            return false;
        }

        try {
            // The lock's session takes this one advisory lock and nothing else.
            return (bool) DB::connection(self::CONNECTION)->selectOne(
                "SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted) AS held",
            )->held;
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
