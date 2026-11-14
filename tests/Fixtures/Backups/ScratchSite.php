<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupLock;
use App\Domain\Backups\DatabaseMoment;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * A migrated database of the test's own, made the default connection, for
 * a restore to replace: pg_restore can't drop tables under the test's own
 * open transaction. Made from a template migrated once a process, or, as a
 * role with production's rights only, migrated by that role. close() in
 * afterEach puts the test's connection back and drops it.
 */
final class ScratchSite
{
    private const string ADMIN = 'teme_scratch_admin';

    /** The template this process made, once (a test's state, not the app's). */
    private static ?string $template = null;

    private function __construct(public readonly string $database, private readonly string $connection, private readonly ?string $role, private readonly string $previous) {}

    /**
     * Opened twice in one test, the second is a new server's database: each
     * has a connection of its own, and close() in reverse order.
     */
    public static function open(bool $asRestrictedRole = false): self
    {
        $previous = DB::getDefaultConnection();
        /** @var array<string, mixed> $base */
        $base = DB::connection()->getConfig();
        $suffix = Str::lower(Str::random(10));
        $database = "teme_scratch_{$suffix}";
        $connection = $database;
        $role = null;

        if ($asRestrictedRole) {
            $role = "teme_restorer_{$suffix}";
            $password = bin2hex(random_bytes(16));
            self::admin($base)->statement("CREATE ROLE {$role} LOGIN PASSWORD '{$password}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS");
            self::admin($base)->statement("CREATE DATABASE {$database} OWNER {$role} TEMPLATE template0");
            $config = [...$base, 'database' => $database, 'username' => $role, 'password' => $password];
        } else {
            self::admin($base)->statement("CREATE DATABASE {$database} TEMPLATE ".self::template($base));
            $config = [...$base, 'database' => $database];
        }

        self::use($connection, $config);

        if ($asRestrictedRole) {
            // Migrated by the role, so it owns every object, as production's role does.
            self::migrateAndSeed();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return new self($database, $connection, $role, $previous);
    }

    public function close(): void
    {
        config(['database.default' => $this->previous]);
        DB::setDefaultConnection($this->previous);

        // Every session a test or the code under it opened on the scratch database.
        foreach ([$this->connection, BackupLock::CONNECTION, DatabaseMoment::CONNECTION, 'backup_committed_media', 'backup_other_session'] as $name) {
            DB::purge($name);
        }

        /** @var array<string, mixed> $base */
        $base = DB::connection()->getConfig();
        self::admin($base)->statement("DROP DATABASE IF EXISTS {$this->database} WITH (FORCE)");

        if ($this->role !== null) {
            self::admin($base)->statement("DROP ROLE IF EXISTS {$this->role}");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function use(string $connection, array $config): void
    {
        config(["database.connections.{$connection}" => [...$config, 'name' => $connection], 'database.default' => $connection]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);
    }

    private static function migrateAndSeed(): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== 0 || Artisan::call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]) !== 0) {
            throw new RuntimeException('A scratch site wouldn’t migrate: '.Artisan::output());
        }
    }

    /**
     * The process's template: made from template0, migrated and seeded once,
     * then left with no session, as CREATE DATABASE … TEMPLATE needs.
     *
     * @param  array<string, mixed>  $base
     */
    private static function template(array $base): string
    {
        if (self::$template !== null) {
            return self::$template;
        }

        // One a process: by the parallel token, or the process's ID in a run without one.
        $template = 'teme_scratch_template_'.(ParallelTesting::token() ?: 'p'.getmypid());
        self::admin($base)->statement("DROP DATABASE IF EXISTS {$template} WITH (FORCE)");
        self::admin($base)->statement("CREATE DATABASE {$template} TEMPLATE template0");
        $previous = DB::getDefaultConnection();
        self::use($template, [...$base, 'database' => $template]);
        self::migrateAndSeed();
        config(['database.default' => $previous]);
        DB::setDefaultConnection($previous);
        DB::purge($template);
        register_shutdown_function(self::dropTemplate(...), $base, $template);

        return self::$template = $template;
    }

    /**
     * Drops the process's template when it ends, through a plain PDO
     * session: by then the application may be gone.
     *
     * @param  array<string, mixed>  $base
     */
    private static function dropTemplate(array $base, string $template): void
    {
        try {
            $pdo = new PDO("pgsql:host={$base['host']};port={$base['port']};dbname=postgres", (string) $base['username'], (string) $base['password']);
            $pdo->exec("DROP DATABASE IF EXISTS {$template} WITH (FORCE)");
        } catch (Throwable) {
            // The next run of this process's name drops it first.
        }
    }

    /**
     * A session on the server's own database, outside any transaction, for
     * CREATE and DROP DATABASE.
     *
     * @param  array<string, mixed>  $base
     */
    private static function admin(array $base): ConnectionInterface
    {
        if (! array_key_exists(self::ADMIN, DB::getConnections())) {
            DB::connectUsing(self::ADMIN, [...$base, 'database' => 'postgres', 'name' => self::ADMIN]);
        }

        return DB::connection(self::ADMIN);
    }
}
