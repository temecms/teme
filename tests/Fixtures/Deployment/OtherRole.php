<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A role with production's rights and nothing of its own, for a test that
 * connects as someone who owns nothing. drop() in afterEach removes it,
 * after whatever it connected to is gone.
 */
final readonly class OtherRole
{
    private const string ADMIN = 'teme_other_role_admin';

    private function __construct(public string $name, public string $password) {}

    public static function make(): self
    {
        $name = 'teme_other_'.Str::lower(Str::random(10));
        $password = bin2hex(random_bytes(16));
        self::admin()->statement("CREATE ROLE {$name} LOGIN PASSWORD '{$password}' NOSUPERUSER NOCREATEDB NOCREATEROLE");

        return new self($name, $password);
    }

    public function drop(): void
    {
        self::admin()->statement("DROP ROLE IF EXISTS {$this->name}");
        DB::purge(self::ADMIN);
    }

    /**
     * A session on the server's own database, outside any transaction, for
     * CREATE and DROP ROLE.
     */
    private static function admin(): ConnectionInterface
    {
        if (! array_key_exists(self::ADMIN, DB::getConnections())) {
            /** @var array<string, mixed> $base */
            $base = DB::connection()->getConfig();
            DB::connectUsing(self::ADMIN, [...$base, 'database' => 'postgres', 'name' => self::ADMIN]);
        }

        return DB::connection(self::ADMIN);
    }
}
