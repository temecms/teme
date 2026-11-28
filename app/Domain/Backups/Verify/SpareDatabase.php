<?php

/**
 * The weekly test restore's spare database (I-2406), and the only code that
 * ever empties a database: named in the configuration, refused unless its
 * name ends in _verify and it isn't the site's own, emptied before and after
 * each test on a session of its own, and filled by pg_restore.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Verify;

use App\Domain\Backups\BackupMode;
use App\Domain\Backups\BackupRefused;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTables;
use App\Domain\Backups\Tools\PostgresProgram;
use App\Domain\Backups\Tools\PostgresTools;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

final class SpareDatabase
{
    /** The connection config/database.php names. */
    public const string CONNECTION = 'teme_verify';

    /** The session open() makes on it. */
    public const string SESSION = 'teme_verify_session';

    public const string SUFFIX = '_verify';

    public const string NOT_NAMED = 'There’s no spare database to test-restore into. At the server, name one as TEME_VERIFY_DATABASE.';

    public const string LIVE = 'The spare database is the site’s own database, and Teme never empties that. Name another as TEME_VERIFY_DATABASE.';

    public const string CANNOT_EMPTY = 'The spare database holds something Teme’s account can’t remove. The log has PostgreSQL’s own words.';

    private bool $open = true;

    /**
     * @param  array<string, mixed>  $config
     */
    private function __construct(private readonly ConnectionInterface $session, public readonly string $name, private readonly array $config, private readonly bool $temporary = false) {}

    /**
     * Whether a test restore goes into a temporary database: a host-run site
     * with no spare database named. An explicitly named spare wins (TEME_BACKUPS=host).
     */
    public static function testRestoreUsesTemporary(): bool
    {
        return BackupMode::isHost() && ! self::isNamed();
    }

    /**
     * Whether the server names a spare database: TEME_VERIFY_DATABASE is set.
     */
    public static function isNamed(): bool
    {
        $name = config('database.connections.'.self::CONNECTION.'.database');

        return is_string($name) && trim($name) !== '';
    }

    /**
     * Opens the spare database after three guards, so nothing here can reach
     * the site's own: the name, the configuration, and what the servers say.
     *
     * @throws BackupRefused
     */
    public static function open(): self
    {
        $config = config('database.connections.'.self::CONNECTION);

        if (! is_array($config) || ! self::isNamed()) {
            throw new BackupRefused(self::NOT_NAMED);
        }

        /** @var array<string, mixed> $config */
        $name = (string) $config['database'];

        // Before connecting: the name reaches pg_restore --dbname, and only a _verify database is ever emptied.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || ! str_ends_with($name, self::SUFFIX)) {
            throw new BackupRefused("Teme test-restores only into a database whose name ends in _verify and holds only letters, digits, dots, hyphens and underscores, and “{$name}” doesn’t.");
        }

        /** @var array<string, mixed> $live */
        $live = DB::connection()->getConfig();

        if ($name === ($live['database'] ?? null) && ($config['host'] ?? null) === ($live['host'] ?? null) && (string) ($config['port'] ?? '') === (string) ($live['port'] ?? '')) {
            throw new BackupRefused(self::LIVE);
        }

        // Named in the configuration too, so Laravel's reconnect after a refused connection finds it.
        config(['database.connections.'.self::SESSION => [...$config, 'name' => self::SESSION]]);

        try {
            $session = DB::connectUsing(self::SESSION, [...$config, 'name' => self::SESSION], force: true);
            $isLive = self::sameDatabase($session, DB::connection());
        } catch (QueryException|PDOException $failed) {
            DB::purge(self::SESSION);
            Log::warning('Backups: reaching the spare database: '.self::hidden($failed->getMessage(), $config));

            throw new BackupRefused("Teme couldn’t reach the spare database “{$name}”. The log has PostgreSQL’s own words.");
        }

        // Another host name, an alias or a proxy can still lead to the site's own database.
        if ($isLive) {
            DB::purge(self::SESSION);

            throw new BackupRefused(self::LIVE);
        }

        return new self($session, $name, $config);
    }

    /**
     * Opens a temporary database for a host-run site's test restore
     * (TEME_BACKUPS=host): one the restore account makes now, with a random
     * name, and that close() drops. The same three guards as open() apply to it.
     *
     * @throws BackupRefused
     */
    public static function openTemporary(): self
    {
        if (! TemporaryDatabase::configured()) {
            throw new BackupRefused(TemporaryDatabase::NOT_CONFIGURED);
        }

        $name = TemporaryDatabase::create();
        /** @var array<string, mixed> $config */
        $config = [...(array) config('database.connections.'.TemporaryDatabase::CONNECTION), 'database' => $name];

        /** @var array<string, mixed> $live */
        $live = DB::connection()->getConfig();

        if ($name === ($live['database'] ?? null) && ($config['host'] ?? null) === ($live['host'] ?? null) && (string) ($config['port'] ?? '') === (string) ($live['port'] ?? '')) {
            TemporaryDatabase::drop($name);

            throw new BackupRefused(self::LIVE);
        }

        config(['database.connections.'.self::SESSION => [...$config, 'name' => self::SESSION]]);

        try {
            $session = DB::connectUsing(self::SESSION, [...$config, 'name' => self::SESSION], force: true);
            $isLive = self::sameDatabase($session, DB::connection());
        } catch (QueryException|PDOException $failed) {
            DB::purge(self::SESSION);
            TemporaryDatabase::drop($name);
            Log::warning('Backups: reaching the temporary restore database: '.self::hidden($failed->getMessage(), $config));

            throw new BackupRefused('Teme couldn’t reach the temporary database for the test restore. The log has PostgreSQL’s own words.');
        }

        if ($isLive) {
            DB::purge(self::SESSION);
            TemporaryDatabase::drop($name);

            throw new BackupRefused(self::LIVE);
        }

        return new self($session, $name, $config, temporary: true);
    }

    /**
     * Whether two sessions reach one database: the same name and OID on a
     * server started at the same instant, which two servers never share.
     */
    public static function sameDatabase(ConnectionInterface $one, ConnectionInterface $other): bool
    {
        return self::identity($one) === self::identity($other);
    }

    /**
     * Empties the spare database (I-2406): every extension but plpgsql, then
     * the public schema and all it holds, made again empty. Its owner may do
     * this without being a superuser; anything it can't remove is refused
     * in words, PostgreSQL's own going to the log.
     *
     * @throws BackupRefused
     */
    public function empty(): void
    {
        $this->emptyOn($this->session);
    }

    /**
     * @throws BackupRefused
     */
    private function emptyOn(ConnectionInterface $session): void
    {
        try {
            foreach ($session->select("SELECT extname FROM pg_catalog.pg_extension WHERE extname <> 'plpgsql' ORDER BY extname") as $row) {
                $session->statement('DROP EXTENSION IF EXISTS '.self::identifier((string) $row->extname).' CASCADE');
            }

            $session->statement('DROP SCHEMA IF EXISTS public CASCADE');
            $session->statement('CREATE SCHEMA public');
        } catch (QueryException $failed) {
            Log::warning('Backups: emptying the spare database: '.self::hidden($failed->getMessage(), $this->config));

            throw new BackupRefused(self::CANNOT_EMPTY);
        }
    }

    /**
     * Restores a dump into the emptied spare database, all or nothing, as
     * Teme's own role: no owners, grants, comments or objects Teme never
     * makes. pg_restore's own words, the password hidden, when it failed;
     * null when the copy is in.
     */
    public function restore(PostgresTools $postgres, string $dump): ?string
    {
        $result = $postgres->run(PostgresProgram::Restore, [
            '--dbname='.$this->name,
            '--single-transaction',
            '--no-owner',
            '--no-privileges',
            '--no-comments',
            '--no-publications',
            '--no-subscriptions',
            '--no-security-labels',
            '--no-tablespaces',
            $dump,
        ], $this->config, BackupSite::WORK_SECONDS);

        return $result->successful() ? null : mb_strimwidth(self::hidden(trim($result->errorOutput()), $this->config), 0, 1000, '…');
    }

    /**
     * Every table's rows in the restored copy, by name, but the tables a
     * backup keeps as structure only, as DatabaseMoment counted them.
     *
     * @return array<string, int>
     */
    public function tableCounts(): array
    {
        $tables = array_values(array_diff(
            array_map(
                fn (object $row): string => (string) $row->name,
                $this->session->select("SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename"),
            ),
            BackupTables::STRUCTURE_ONLY,
        ));

        if ($tables === []) {
            return [];
        }

        // One statement. Names come from the catalog, quoted as identifiers, and bound as values.
        $sql = implode(' UNION ALL ', array_map(
            fn (string $table): string => 'SELECT ?::text AS name, count(*) AS n FROM public.'.self::identifier($table),
            $tables,
        ));

        $counts = [];

        foreach ($this->session->select($sql, $tables) as $row) {
            $counts[(string) $row->name] = (int) $row->n;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The restored copy's newest migration by name, or null when it has no
     * migrations table or no row in it.
     */
    public function newestMigration(): ?string
    {
        if (! (bool) $this->session->selectOne("SELECT to_regclass('public.migrations') IS NOT NULL AS present")->present) {
            return null;
        }

        $newest = $this->session->selectOne('SELECT max(migration) AS newest FROM public.migrations')->newest;

        return $newest === null ? null : (string) $newest;
    }

    /**
     * Every way the restored copy differs from the manifest, in words: a
     * table's rows, a table missing or extra, the newest migration.
     *
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $found
     *
     * @return list<string>
     */
    public static function differences(array $expected, array $found, ?string $expectedNewest, ?string $foundNewest): array
    {
        $differences = [];

        foreach ($expected as $table => $rows) {
            if (! array_key_exists($table, $found)) {
                $differences[] = "{$table} isn’t in the restored copy.";
            } elseif ($found[$table] !== $rows) {
                $differences[] = sprintf('%s has %s in the restored copy, and %s in the manifest.', $table, $found[$table] === 1 ? '1 row' : number_format($found[$table]).' rows', number_format($rows));
            }
        }

        foreach (array_keys(array_diff_key($found, $expected)) as $table) {
            $differences[] = "{$table} is in the restored copy, but not in the manifest.";
        }

        if ($expectedNewest !== $foundNewest) {
            $differences[] = sprintf('The restored copy’s newest migration is %s, and the manifest’s is %s.', $foundNewest ?? 'none', $expectedNewest ?? 'none');
        }

        return $differences;
    }

    /**
     * Empties the spare database again, whatever happened, and closes its
     * session. When the session that watched the restore can't, a fresh one
     * tries once more: PostgreSQL has refused it with a stale view of the
     * catalog pg_restore changed (#216). Each failure is logged; the next
     * test empties it first.
     */
    public function close(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;

        // A temporary database is dropped whole, so there is nothing to empty first.
        if ($this->temporary) {
            DB::purge(self::SESSION);

            try {
                TemporaryDatabase::drop($this->name);
            } catch (Throwable $failed) {
                Log::warning("Backups: dropping the temporary restore database {$this->name}: ".$failed->getMessage());
            }

            return;
        }

        try {
            $this->empty();
        } catch (Throwable) {
            DB::purge(self::SESSION);

            try {
                $this->emptyOn(DB::connectUsing(self::SESSION, [...$this->config, 'name' => self::SESSION], force: true));
            } catch (BackupRefused) {
                // emptyOn() has logged PostgreSQL's words.
            } catch (Throwable $failed) {
                Log::warning('Backups: emptying the spare database: '.self::hidden($failed->getMessage(), $this->config));
            }
        }

        DB::purge(self::SESSION);
    }

    private static function identity(ConnectionInterface $session): string
    {
        // The start time as microseconds since the epoch: as text it would follow each session's time zone.
        $row = $session->selectOne('SELECT current_database() AS name, (SELECT oid FROM pg_catalog.pg_database WHERE datname = current_database()) AS oid, (extract(epoch FROM pg_postmaster_start_time()) * 1000000)::bigint AS started');

        return "{$row->name}|{$row->oid}|{$row->started}";
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function hidden(string $words, array $config): string
    {
        $password = (string) ($config['password'] ?? '');

        return $password === '' ? $words : str_replace($password, '[hidden]', $words);
    }
}
