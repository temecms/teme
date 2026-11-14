<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\Verify\SpareDatabase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A real, empty spare database for one test (I-2406), named as the
 * teme_verify connection's database: made from template0, optionally owned
 * by a role with production's rights only, and probed as the test's own
 * superuser. drop() in afterEach removes it, and the role.
 */
final class VerifyDatabase
{
    private const string ADMIN = 'teme_verify_admin';

    private const string PROBE = 'teme_verify_probe';

    private function __construct(public readonly string $name, private readonly ?string $role) {}

    public static function make(bool $asRestrictedRole = false): self
    {
        $suffix = Str::lower(Str::random(10));
        $name = "teme_t{$suffix}_verify";
        $role = null;
        $config = [...self::base(), 'database' => $name];

        if ($asRestrictedRole) {
            $role = "teme_verifier_{$suffix}";
            $password = bin2hex(random_bytes(16));
            self::admin()->statement("CREATE ROLE {$role} LOGIN PASSWORD '{$password}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS");
            self::admin()->statement("CREATE DATABASE {$name} OWNER {$role} TEMPLATE template0");
            $config = [...$config, 'username' => $role, 'password' => $password];
        } else {
            self::admin()->statement("CREATE DATABASE {$name} TEMPLATE template0");
        }

        config(['database.connections.'.SpareDatabase::CONNECTION => $config]);
        DB::purge(SpareDatabase::CONNECTION);

        return new self($name, $role);
    }

    /**
     * No spare database named, whatever phpunit.xml or a .env says.
     */
    public static function unnamed(): void
    {
        config(['database.connections.'.SpareDatabase::CONNECTION.'.database' => null]);
    }

    public function run(string $sql): void
    {
        $this->probe()->statement($sql);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->names("SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return $this->names('SELECT extname AS name FROM pg_catalog.pg_extension ORDER BY extname');
    }

    /**
     * @return list<string>
     */
    public function schemas(): array
    {
        return $this->names('SELECT nspname AS name FROM pg_catalog.pg_namespace ORDER BY nspname');
    }

    public function drop(): void
    {
        foreach ([SpareDatabase::SESSION, SpareDatabase::CONNECTION, self::PROBE] as $connection) {
            DB::purge($connection);
        }

        self::admin()->statement("DROP DATABASE IF EXISTS {$this->name} WITH (FORCE)");

        if ($this->role !== null) {
            self::admin()->statement("DROP ROLE IF EXISTS {$this->role}");
        }

        DB::purge(self::ADMIN);
    }

    /**
     * @return list<string>
     */
    private function names(string $sql): array
    {
        return array_map(fn (object $row): string => (string) $row->name, $this->probe()->select($sql));
    }

    private function probe(): ConnectionInterface
    {
        if (! array_key_exists(self::PROBE, DB::getConnections())) {
            DB::connectUsing(self::PROBE, [...self::base(), 'database' => $this->name, 'name' => self::PROBE]);
        }

        return DB::connection(self::PROBE);
    }

    /**
     * A session on the server's own database, outside any transaction, for
     * CREATE and DROP DATABASE and ROLE.
     */
    private static function admin(): ConnectionInterface
    {
        if (! array_key_exists(self::ADMIN, DB::getConnections())) {
            DB::connectUsing(self::ADMIN, [...self::base(), 'database' => 'postgres', 'name' => self::ADMIN]);
        }

        return DB::connection(self::ADMIN);
    }

    /**
     * The test's own connection's configuration (its worker's database in a
     * parallel run), without its name.
     *
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();
        unset($config['name']);

        return $config;
    }
}
