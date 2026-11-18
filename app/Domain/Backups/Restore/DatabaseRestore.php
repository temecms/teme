<?php

/**
 * Replaces this site's database with a backup's, the one class that writes
 * SQL outside an Operation (docs/architecture/operations.md): the
 * dump fetched into a private file, the tables it lacks dropped, the dump
 * restored by pg_restore in one transaction with the cache's tables and the
 * installed extensions left as they are, the cache emptied but for
 * maintenance mode's flag, and this Teme's newer migrations run.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\PostgresProgram;
use App\Domain\Backups\Tools\PostgresTools;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Support\Security\Elevation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class DatabaseRestore
{
    public function __construct(private Restic $restic, private PostgresTools $postgres) {}

    /**
     * Writes the run's dump to $file, never through PHP's memory.
     *
     * @throws RestoreStopped
     */
    public function download(ResticRepository $repository, string $snapshotId, string $file, string $place): void
    {
        $fetched = $this->restic->run($repository, ['dump', '--target', $file, $snapshotId, BackupSnapshots::DUMP_FILE], timeout: RestoreSite::WORK_SECONDS);

        if (! $fetched->succeeded()) {
            Log::warning("Backups: fetching the database at {$place}: {$fetched->detail()}");

            throw new RestoreStopped($fetched->sentence($place));
        }
    }

    /**
     * pg_restore's table of contents for the dump, one entry a line.
     *
     * @return list<string>
     *
     * @throws RestoreStopped
     */
    public function contents(string $file): array
    {
        $listed = $this->postgres->run(PostgresProgram::Restore, ['--list', $file]);

        if (! $listed->successful()) {
            Log::warning('Backups: reading the copy of the database: '.self::lastWords($listed->errorOutput()));

            throw new RestoreStopped('pg_restore couldn’t read the copy of the database. The log has its own words.');
        }

        return array_values(array_filter(
            array_map(rtrim(...), preg_split('/\R/', $listed->output()) ?: []),
            fn (string $line): bool => preg_match('/^\d+; /', $line) === 1,
        ));
    }

    /**
     * The tables the database cache stores use that this database has: the
     * restore leaves them as they are, so maintenance mode's flag is never
     * dropped and they are never locked.
     *
     * @return list<string>
     */
    public static function keptTables(): array
    {
        $tables = [];
        $here = (string) (DB::connection()->getConfig()['database'] ?? '');

        foreach ((array) config('cache.stores') as $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }

            // A store on a connection of its own (DB_CACHE_CONNECTION) is this database's when it names it.
            $connection = $store['connection'] ?? null;

            if ($connection === null || (string) config("database.connections.{$connection}.database") === $here) {
                $tables[] = (string) ($store['table'] ?? 'cache');
                $tables[] = (string) ($store['lock_table'] ?? 'cache_locks');
            }
        }

        return array_values(array_filter(array_unique($tables), Schema::hasTable(...)));
    }

    /**
     * The indexes those tables have here, by name, as pg_restore lists them.
     *
     * @param  list<string>  $tables
     *
     * @return list<string>
     */
    public static function indexesOf(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($tables), '?'));

        return array_map(fn (object $row): string => (string) $row->indexname, DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ({$marks}) ORDER BY indexname",
            $tables,
        ));
    }

    /**
     * The extensions this database has: dropping one needs its owner, and
     * indexes use it, so the restore keeps them.
     *
     * @return list<string>
     */
    public static function installedExtensions(): array
    {
        return array_map(fn (object $row): string => (string) $row->extname, DB::select("SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY extname"));
    }

    /**
     * pg_restore's list without what the restore leaves as it is: every entry
     * of a kept table (the table, its data, its statistics, its constraints,
     * its indexes) and every extension already installed.
     *
     * @param  list<string>  $contents
     * @param  list<string>  $keptTables
     * @param  list<string>  $keptIndexes
     * @param  list<string>  $installed
     *
     * @return list<string>
     */
    public static function useList(array $contents, array $keptTables, array $keptIndexes, array $installed): array
    {
        // pg_restore --list: "{id}; {catalog} {oid} {kind} {schema} {name} {owner}".
        $patterns = [];

        foreach ($keptTables as $table) {
            $name = preg_quote($table, '/');
            $patterns[] = '/^\d+; \d+ \d+ (TABLE|TABLE DATA|STATISTICS DATA) public '.$name.'( \S*)?$/';
            $patterns[] = '/^\d+; \d+ \d+ (CONSTRAINT|FK CONSTRAINT|TRIGGER) public '.$name.' \S+( \S*)?$/';
        }

        foreach ($keptIndexes as $index) {
            $patterns[] = '/^\d+; \d+ \d+ INDEX public '.preg_quote($index, '/').'( \S*)?$/';
        }

        foreach ($installed as $extension) {
            $patterns[] = '/^\d+; \d+ \d+ EXTENSION - '.preg_quote($extension, '/').'( \S*)?$/';
        }

        return array_values(array_filter($contents, function (string $line) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The tables this database has that the dump doesn't, but the kept ones:
     * a newer Teme's, when the backup is older.
     *
     * @param  list<string>  $contents
     * @param  list<string>  $keptTables
     *
     * @return list<string>
     */
    public static function tablesTheDumpLacks(array $contents, array $keptTables): array
    {
        $dumped = [];

        foreach ($contents as $line) {
            if (preg_match('/^\d+; \d+ \d+ TABLE public (\S+)( \S*)?$/', $line, $match) === 1) {
                $dumped[] = $match[1];
            }
        }

        $here = array_map(fn (object $row): string => (string) $row->relname, DB::select(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition ORDER BY c.relname",
        ));

        return array_values(array_diff($here, $dumped, $keptTables));
    }

    /**
     * Drops them, and whatever depends on them, in one transaction: the one
     * change before pg_restore that its own transaction can't undo, so the
     * safety backup comes first (I-363).
     *
     * @param  list<string>  $tables
     *
     * @throws RestoreStopped
     */
    public function dropTables(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $names = implode(', ', array_map(fn (string $table): string => 'public."'.str_replace('"', '""', $table).'"', $tables));

        try {
            DB::transaction(fn () => DB::statement("DROP TABLE IF EXISTS {$names} CASCADE"));
        } catch (Throwable $failed) {
            Log::warning('Backups: removing tables: '.self::lastWords($failed->getMessage()));

            throw new RestoreStopped('Teme couldn’t remove the tables the backup doesn’t have. The log has PostgreSQL’s words.');
        }
    }

    /**
     * Replaces the database with the dump's, all of it or none, as Teme's own
     * role, which isn't a superuser. Nothing the process read before is used
     * after it, and a failure leaves both as they were.
     *
     * @throws RestoreStopped
     */
    public function restore(string $file, string $list): void
    {
        $restored = $this->postgres->run(PostgresProgram::Restore, [
            '--dbname='.(string) DB::connection()->getConfig()['database'],
            '--single-transaction',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--no-comments',
            '--no-publications',
            '--no-subscriptions',
            '--no-security-labels',
            '--no-tablespaces',
            '--use-list='.$list,
            $file,
        ], timeout: RestoreSite::WORK_SECONDS);

        // Failed, it rolled back: the database and this process's view of it are as they were.
        if (! $restored->successful()) {
            Log::warning('Backups: restoring the database: '.self::lastWords($restored->errorOutput()));

            throw new RestoreStopped('pg_restore couldn’t restore the database. The log has its own words.');
        }

        // A fresh session in the same connection object, which the cache store and others hold.
        DB::reconnect();

        // Elevation is scoped too: forgotten, every Operation after this would refuse the operator.
        $elevation = app(Elevation::class);
        app()->forgetScopedInstances();
        app()->instance(Elevation::class, $elevation);
    }

    /**
     * Empties every database cache store's rows but maintenance mode's flag,
     * one statement a table, so a request never finds the flag gone; flushes
     * the default store when it is another kind. A flag kept there is put
     * back by the window (MaintenanceWindow::reassert()).
     */
    public function emptyCache(): void
    {
        foreach ((array) config('cache.stores') as $name => $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }

            $connection = DB::connection($store['connection'] ?? null);
            $table = (string) ($store['table'] ?? 'cache');
            $locks = (string) ($store['lock_table'] ?? 'cache_locks');
            $flag = MaintenanceWindow::flagKey((string) $name);

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $connection->transaction(function () use ($connection, $table, $locks, $flag): void {
                $connection->table($table)->when($flag !== null, fn (Builder $rows): Builder => $rows->where('key', '<>', $flag))->delete();

                if ($connection->getSchemaBuilder()->hasTable($locks)) {
                    $connection->table($locks)->delete();
                }
            });
        }

        $default = (string) config('cache.default');

        if (config("cache.stores.{$default}.driver") !== 'database') {
            Cache::store($default)->flush();
        }
    }

    /**
     * Runs this Teme's migrations the restored database hasn't, and says how many ran.
     *
     * @throws RestoreStopped
     */
    public function migrate(): int
    {
        $pending = RestoreCompatibility::pendingMigrations();

        if ($pending === 0) {
            return 0;
        }

        $status = Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();

        if ($status !== 0) {
            Log::warning('Backups: running migrations: '.self::lastWords($output));

            throw new RestoreStopped('This Teme’s migrations failed on the restored database. The log has their output.');
        }

        return $pending;
    }

    /**
     * A program's last line, at most 1,000 characters: pg_restore and
     * PostgreSQL never print a password, which only their environment holds.
     */
    private static function lastWords(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [''];

        return mb_strimwidth((string) end($lines), 0, 1000, '…');
    }
}
