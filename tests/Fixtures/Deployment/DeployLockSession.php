<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use App\Domain\Deployment\DeployLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A session of its own on the test's default database, as another deploy
 * holds one: it takes the deploy lock, and counts the deploys waiting for
 * it. close() in afterEach lets go of anything it held, then disconnects.
 */
final class DeployLockSession
{
    private const string NAME = 'deploy_lock_session';

    /**
     * Takes the deploy lock, as a deploy in another process holds it.
     */
    public static function hold(): void
    {
        if (! (bool) self::connection()->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS taken', [DeployLock::KEY])->taken) {
            throw new RuntimeException('Another session already holds the deploy lock.');
        }
    }

    /**
     * How many sessions wait for an advisory lock on this database.
     */
    public static function waiting(): int
    {
        return (int) self::connection()->scalar(<<<'SQL'
            SELECT count(*) FROM pg_catalog.pg_locks
            WHERE locktype = 'advisory' AND NOT granted
              AND database = (SELECT oid FROM pg_catalog.pg_database WHERE datname = current_database())
            SQL);
    }

    /**
     * Waits until $count sessions wait for the lock, for up to a minute.
     */
    public static function waitForWaiting(int $count): void
    {
        $until = microtime(true) + 60;

        while (self::waiting() < $count) {
            if (microtime(true) > $until) {
                throw new RuntimeException("Fewer than {$count} deploys waited for the lock within a minute.");
            }

            usleep(100_000);
        }
    }

    /**
     * Ends every other session holding an advisory lock on this database,
     * as a database restart or a killed backend ends a deploy's: call it
     * once no other fixture holds one.
     */
    public static function endLockHolders(): void
    {
        self::connection()->select(<<<'SQL'
            SELECT pg_terminate_backend(pid) FROM pg_catalog.pg_locks
            WHERE locktype = 'advisory' AND granted AND pid <> pg_backend_pid()
              AND database = (SELECT oid FROM pg_catalog.pg_database WHERE datname = current_database())
            SQL);
    }

    public static function close(): void
    {
        if (array_key_exists(self::NAME, DB::getConnections())) {
            try {
                DB::connection(self::NAME)->select('SELECT pg_advisory_unlock_all()');
            } catch (Throwable) {
                // Closing it, below, lets go instead.
            }
        }

        DB::purge(self::NAME);
    }

    private static function connection(): ConnectionInterface
    {
        if (! array_key_exists(self::NAME, DB::getConnections())) {
            /** @var array<string, mixed> $config */
            $config = DB::connection()->getConfig();
            DB::connectUsing(self::NAME, [...$config, 'name' => self::NAME]);
        }

        return DB::connection(self::NAME);
    }
}
