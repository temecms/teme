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

    public const string PREFIX = 'teme_restore_';

    /** The only names this class creates or drops: the prefix and sixteen hexadecimal digits. */
    public const string PATTERN = '/^teme_restore_[0-9a-f]{16}$/';

    public const string NOT_CONFIGURED = 'Under TEME_BACKUPS=host, Teme’s test restore makes a temporary database with a restore account of its own. At the server, name that account as TEME_RESTORE_DB_USERNAME, with a password and CREATEDB.';

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

        $name = self::PREFIX.bin2hex(random_bytes(8));

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
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("“{$name}” isn’t a temporary restore database Teme made.");
        }

        DB::connection(self::CONNECTION)->statement('DROP DATABASE IF EXISTS '.self::identifier($name).' WITH (FORCE)');
        DB::purge(self::CONNECTION);
    }

    /**
     * Drops every temporary restore database a run left behind, and returns how
     * many it dropped. Each failure is logged and the next one still goes on.
     */
    public static function sweep(): int
    {
        if (! self::configured()) {
            return 0;
        }

        $dropped = 0;

        try {
            $names = DB::connection(self::CONNECTION)->select("SELECT datname AS name FROM pg_catalog.pg_database WHERE datname LIKE 'teme\\_restore\\_%'");
        } catch (Throwable $failed) {
            Log::warning('Backups: listing temporary restore databases: '.$failed->getMessage());

            return 0;
        } finally {
            DB::purge(self::CONNECTION);
        }

        foreach ($names as $row) {
            $name = (string) $row->name;

            if (preg_match(self::PATTERN, $name) !== 1) {
                continue;
            }

            try {
                self::drop($name);
                $dropped++;
            } catch (Throwable $failed) {
                Log::warning("Backups: dropping the leftover temporary restore database {$name}: ".$failed->getMessage());
            }
        }

        return $dropped;
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
