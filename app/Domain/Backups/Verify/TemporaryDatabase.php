<?php

/**
 * The database a host-run site's test restore uses (TEME_BACKUPS=host): made
 * by the restore account, which holds CREATEDB, and dropped again after each
 * test, so no second copy of the site's data is left on the live server. The
 * site's own account stays NOCREATEDB. A run that dies leaves its copy behind,
 * so sweep() drops any database named with this prefix before a test and daily.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Verify;

use App\Domain\Backups\BackupRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Random\RandomException;
use Throwable;

final class TemporaryDatabase
{
    /** The connection config/database.php names for the restore account. */
    public const string CONNECTION = 'teme_restore';

    /** The start of every name this class makes: the prefix, then this site's tag. */
    public const string PREFIX = 'teme_restore_';

    public const string NOT_CONFIGURED = 'Under TEME_BACKUPS=host, Teme’s test restore makes a temporary database with a restore account of its own. At the server, name that account as TEME_RESTORE_DB_USERNAME, with a password and CREATEDB.';

    /**
     * The start of this site's names: the prefix and the site's tag, so two
     * sites on one server, or one site's parallel test workers, never sweep
     * each other's. The tag is a short hash of the database's name.
     */
    public static function prefix(): string
    {
        return self::PREFIX.substr(hash('sha256', (string) DB::connection()->getDatabaseName()), 0, 8).'_';
    }

    /**
     * The only names this class creates or drops: this site's prefix and
     * sixteen lower-case hexadecimal digits, to the very end of the name.
     */
    public static function pattern(): string
    {
        return '/^'.preg_quote(self::prefix(), '/').'[0-9a-f]{16}\z/';
    }

    /**
     * Whether the restore account is named.
     */
    public static function configured(): bool
    {
        $username = config('database.connections.'.self::CONNECTION.'.username');

        return is_string($username) && trim($username) !== '';
    }

    /**
     * Makes an empty database with a random name, owned by the restore account.
     *
     * @throws BackupRefused
     * @throws RandomException
     */
    public static function create(): string
    {
        if (! self::configured()) {
            throw new BackupRefused(self::NOT_CONFIGURED);
        }

        $name = self::prefix().bin2hex(random_bytes(8));

        try {
            DB::connection(self::CONNECTION)->statement('CREATE DATABASE '.self::identifier($name).' TEMPLATE template0');
        } catch (Throwable $failed) {
            Log::warning('Backups: making the temporary restore database: '.$failed->getMessage());

            throw new BackupRefused('Teme couldn’t make the temporary database for the test restore. The log has PostgreSQL’s own words.');
        } finally {
            DB::purge(self::CONNECTION);
        }

        return $name;
    }

    /**
     * Drops one database this class made, after refusing any name it didn't make.
     *
     * @throws InvalidArgumentException
     */
    public static function drop(string $name): void
    {
        if (preg_match(self::pattern(), $name) !== 1) {
            throw new InvalidArgumentException("“{$name}” isn’t a temporary restore database Teme made.");
        }

        // Forced: the only session left is the one this run has just closed, which PostgreSQL may still be ending.
        DB::connection(self::CONNECTION)->statement('DROP DATABASE IF EXISTS '.self::identifier($name).' WITH (FORCE)');
        DB::purge(self::CONNECTION);
    }

    /**
     * Drops this site's temporary restore databases that a dead run left
     * behind, and returns how many it dropped. Only databases this site's
     * restore account owns, that nothing is connected to, and whose names
     * carry this site's prefix are touched; the caller holds the backup lock,
     * so a restore running now is never one of them. Each failure is logged
     * and the next one still goes on.
     */
    public static function sweep(): int
    {
        if (! self::configured()) {
            return 0;
        }

        $dropped = 0;

        try {
            $candidates = DB::connection(self::CONNECTION)->select(
                <<<'SQL'
                SELECT d.datname AS name
                FROM pg_catalog.pg_database AS d
                WHERE d.datname LIKE ?
                  AND pg_catalog.pg_get_userbyid(d.datdba) = current_user
                  AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_stat_activity AS a WHERE a.datname = d.datname)
                SQL,
                [str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], self::prefix()).'%'],
            );
        } catch (Throwable $failed) {
            Log::warning('Backups: listing temporary restore databases: '.$failed->getMessage());

            return 0;
        } finally {
            DB::purge(self::CONNECTION);
        }

        foreach ($candidates as $row) {
            $name = (string) $row->name;

            if (preg_match(self::pattern(), $name) !== 1) {
                continue;
            }

            try {
                // Not forced: a session that connected since the check keeps its database.
                DB::connection(self::CONNECTION)->statement('DROP DATABASE IF EXISTS '.self::identifier($name));
                $dropped++;
            } catch (Throwable $failed) {
                Log::warning("Backups: dropping the leftover temporary restore database {$name}: ".$failed->getMessage());
            } finally {
                DB::purge(self::CONNECTION);
            }
        }

        return $dropped;
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
