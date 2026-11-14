<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A second connection to the test's own database, as another process holds
 * one: the backup lock is PostgreSQL's, per session, so only another
 * session shows what a queue worker or the scheduler sees. close() in
 * afterEach lets go of anything it held, then disconnects it.
 */
final class OtherSession
{
    private const string NAME = 'backup_other_session';

    /**
     * Whether a backup holds the lock, as another process would ask.
     */
    public static function backupRunning(): bool
    {
        $free = (bool) self::connection()->selectOne('SELECT pg_try_advisory_lock_shared(hashtext(?)) AS free', [BackupLock::KEY])->free;

        if ($free) {
            self::connection()->select('SELECT pg_advisory_unlock_shared(hashtext(?))', [BackupLock::KEY]);
        }

        return ! $free;
    }

    /**
     * Takes the backup lock, as another process's backup would.
     */
    public static function holdBackupLock(): void
    {
        if (! (bool) self::connection()->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS taken', [BackupLock::KEY])->taken) {
            throw new RuntimeException('Another session already holds the backup lock.');
        }
    }

    /**
     * Lets go at once, as BackupLock does: a closed session's locks go only
     * when its server process ends, which the next test's session can see
     * late (I-358). Closing stays as the fallback.
     */
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
