<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A role the test makes, with production's rights unless it asks for
 * more, and a database of the same name, made the default connection. The
 * sandbox's own role is a superuser, so a check that passes as it proves
 * nothing (I-379). close() puts the test's connection back and drops both.
 */
final class RestrictedRole
{
    private const string ADMIN = 'teme_doctor_admin';

    /**
     * @param  array<string, mixed>  $base
     */
    private function __construct(
        public readonly string $role,
        private readonly string $previous,
        private readonly array $base,
    ) {}

    /**
     * @param  list<string>  $rights  CREATEROLE, CREATEDB, beyond production's
     * @param  bool  $ownsDatabase  false: the sandbox's superuser owns it
     */
    public static function open(array $rights = [], bool $ownsDatabase = true): self
    {
        $previous = DB::getDefaultConnection();
        /** @var array<string, mixed> $base */
        $base = DB::connection()->getConfig();
        $role = 'teme_doctor_'.Str::lower(Str::random(10));
        $password = bin2hex(random_bytes(16));
        $granted = implode(' ', [
            'NOSUPERUSER',
            in_array('CREATEROLE', $rights, true) ? 'CREATEROLE' : 'NOCREATEROLE',
            in_array('CREATEDB', $rights, true) ? 'CREATEDB' : 'NOCREATEDB',
        ]);

        self::admin($base)->statement("CREATE ROLE {$role} LOGIN PASSWORD '{$password}' {$granted}");
        self::admin($base)->statement("CREATE DATABASE {$role} TEMPLATE template0".($ownsDatabase ? " OWNER {$role}" : ''));

        config([
            "database.connections.{$role}" => [...$base, 'database' => $role, 'username' => $role, 'password' => $password, 'name' => $role],
            'database.default' => $role,
        ]);
        DB::setDefaultConnection($role);

        return new self($role, $previous, $base);
    }

    /**
     * A table in the role's database that the sandbox's superuser owns.
     */
    public function tableOwnedByAnother(string $name): void
    {
        $connection = $this->role.'_as_admin';
        DB::connectUsing($connection, [...$this->base, 'database' => $this->role, 'name' => $connection]);
        DB::connection($connection)->statement("CREATE TABLE {$name} (id integer)");
        DB::purge($connection);
    }

    public function close(): void
    {
        config(['database.default' => $this->previous]);
        DB::setDefaultConnection($this->previous);
        DB::purge($this->role);
        self::admin($this->base)->statement("DROP DATABASE IF EXISTS {$this->role} WITH (FORCE)");
        self::admin($this->base)->statement("DROP ROLE IF EXISTS {$this->role}");
    }

    /**
     * A session on the server's own database, outside any transaction, for
     * CREATE and DROP ROLE and DATABASE.
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
