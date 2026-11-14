<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The recipe's database init, run against the test server as psql would
 * send it, under names of the test's own, so a test can look at the role
 * and the databases it makes. drop() in afterEach removes them.
 */
final class DatabaseInit
{
    private const string ADMIN = 'teme_initdb_admin';

    /**
     * @return array{role: string, database: string, verify: string, password: string}
     */
    public static function names(): array
    {
        $name = 'teme_initdb_'.Str::lower(Str::random(10));

        return ['role' => $name, 'database' => $name, 'verify' => "{$name}_verify", 'password' => bin2hex(random_bytes(16))];
    }

    /** The SQL the script hands psql, between its <<'SQL' and SQL lines. */
    public static function sql(): string
    {
        if (preg_match("/<<'SQL'\n(.*?)\nSQL\n/s", ImageFiles::text('docker/postgres/initdb.d/10-teme-role.sh'), $found) !== 1) {
            throw new RuntimeException("The init script hands psql no SQL between <<'SQL' and SQL.");
        }

        return $found[1];
    }

    /**
     * The statements psql sends, with the script's variables: :"name" as a
     * quoted identifier, :'name' as a quoted literal, and \getenv's
     * variable from the password the script exports.
     *
     * @param  array{role: string, database: string, verify: string, password: string}  $names
     *
     * @return list<string>
     */
    public static function statements(array $names): array
    {
        $variables = ['teme_role' => $names['role'], 'teme_database' => $names['database'], 'teme_verify_database' => $names['verify']];
        $sql = '';

        foreach (explode("\n", self::sql()) as $line) {
            if (preg_match('/^\\\\getenv (\w+) TEME_DB_PASSWORD$/', $line, $found) === 1) {
                $variables[$found[1]] = $names['password'];

                continue;
            }

            if (trim($line) !== '' && ! str_starts_with(trim($line), '--')) {
                $sql .= $line."\n";
            }
        }

        $sql = (string) preg_replace_callback('/:"(\w+)"|:\'(\w+)\'/', function (array $match) use ($variables): string {
            $name = $match[1] ?? $match[2];

            if (! array_key_exists((string) $name, $variables)) {
                throw new RuntimeException("psql would leave :{$name} unset.");
            }

            $value = $variables[(string) $name];

            return $match[1] !== null ? '"'.str_replace('"', '""', $value).'"' : "'".str_replace("'", "''", $value)."'";
        }, $sql, flags: PREG_UNMATCHED_AS_NULL);

        return array_values(array_filter(array_map(trim(...), explode(";\n", $sql)), fn (string $statement): bool => $statement !== ''));
    }

    /**
     * @param  array{role: string, database: string, verify: string, password: string}  $names
     */
    public static function run(array $names): void
    {
        foreach (self::statements($names) as $statement) {
            self::admin()->statement($statement);
        }
    }

    /**
     * A session as the role the script made, on one of its databases.
     *
     * @param  array{role: string, database: string, verify: string, password: string}  $names
     */
    public static function connectAs(array $names, string $database): ConnectionInterface
    {
        $connection = "teme_initdb_as_{$database}";
        DB::connectUsing($connection, [...self::base(), 'database' => $database, 'username' => $names['role'], 'password' => $names['password'], 'name' => $connection]);

        return DB::connection($connection);
    }

    /** A session on the server's own database, outside any transaction, as the test's role. */
    public static function admin(): ConnectionInterface
    {
        if (! array_key_exists(self::ADMIN, DB::getConnections())) {
            DB::connectUsing(self::ADMIN, [...self::base(), 'database' => 'postgres', 'name' => self::ADMIN]);
        }

        return DB::connection(self::ADMIN);
    }

    /**
     * @param  array{role: string, database: string, verify: string, password: string}  $names
     */
    public static function drop(array $names): void
    {
        foreach ([$names['database'], $names['verify']] as $database) {
            DB::purge("teme_initdb_as_{$database}");
            self::admin()->statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
        }

        self::admin()->statement("DROP ROLE IF EXISTS \"{$names['role']}\"");
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('database.connections.pgsql');

        return $config;
    }
}
