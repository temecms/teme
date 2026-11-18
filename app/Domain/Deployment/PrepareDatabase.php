<?php

/**
 * The database step of a container that runs every role: given the login to a PostgreSQL service, makes
 * Teme's own limited login, gives it the site's database and a spare one for the weekly test restore, and lets
 * no other login connect. Safe to run on every start. A login that is no superuser can't do this, so Teme runs as it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use SensitiveParameter;

final class PrepareDatabase
{
    /** The longest name PostgreSQL keeps for a role. */
    private const int NAME_LIMIT = 63;

    /**
     * Returns the login Teme runs as.
     *
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $given
     * @param  string|null  $spareDatabase  the spare database the operator named, left as it is; none makes one
     *
     * @throws DatabasePrepareFailed
     */
    public function run(#[SensitiveParameter] array $given, #[SensitiveParameter] string $appKey, ?string $spareDatabase = null): DatabaseLogin
    {
        $session = $this->connect($given, $given['database']);

        try {
            $row = $session->selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');

            if (! ($row->rolsuper ?? false)) {
                return new DatabaseLogin($given['host'], $given['port'], $given['database'], $given['username'], $given['password'], $spareDatabase, created: false);
            }

            $login = self::loginFor($given['database']);
            $password = hash_hmac('sha256', "teme-database-login:{$given['database']}", $appKey);
            $verify = $spareDatabase ?? "{$given['database']}_verify";

            $this->makeLogin($session, $login, $password);
            $this->giveDatabase($session, $given['database'], $login);

            if ($spareDatabase === null) {
                $this->makeSpare($session, $verify, $login);
            }

            $this->handOverTables($session, $login);

            return new DatabaseLogin($given['host'], $given['port'], $given['database'], $login, $password, $verify, created: true);
        } catch (QueryException|PDOException) {
            throw new DatabasePrepareFailed("Teme couldn't make its own database login as {$given['username']}, so it stopped. Give Teme a PostgreSQL superuser, or make the login by hand as docs/guides/operators/database.md says.");
        } finally {
            DB::purge($session->getName());
        }
    }

    /**
     * Returns the name of the login Teme runs as for a database: made from the database's name, so two sites that
     * share one server never share a login, and one never resets the other's password.
     */
    public static function loginFor(string $database): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($database)), '_');

        return substr('teme_app_'.$slug, 0, self::NAME_LIMIT);
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $given
     *
     * @throws DatabasePrepareFailed
     */
    private function connect(#[SensitiveParameter] array $given, string $database): Connection
    {
        $name = 'teme_prepare_'.bin2hex(random_bytes(4));
        /** @var array<string, mixed> $base */
        $base = config('database.connections.pgsql');

        DB::connectUsing($name, [
            ...$base,
            'url' => null,
            'host' => $given['host'],
            'port' => $given['port'],
            'database' => $database,
            'username' => $given['username'],
            'password' => $given['password'],
            'name' => $name,
        ]);

        try {
            $connection = DB::connection($name);
            $connection->select('SELECT 1');

            return $connection;
        } catch (QueryException|PDOException) {
            DB::purge($name);

            // The driver's message is left out: it can repeat the address and the login.
            throw new DatabasePrepareFailed("Teme couldn't connect to the database at {$given['host']}:{$given['port']} as {$given['username']}. Check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD, or DATABASE_URL, against your database's own settings.");
        }
    }

    private function makeLogin(Connection $session, string $login, #[SensitiveParameter] string $password): void
    {
        if ($session->selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$login]) === null) {
            $session->statement('CREATE ROLE '.self::identifier($session, $login).' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
        }

        $session->statement('ALTER ROLE '.self::identifier($session, $login).' PASSWORD '.self::literal($session, $password));
    }

    private function giveDatabase(Connection $session, string $database, string $login): void
    {
        $session->statement('ALTER DATABASE '.self::identifier($session, $database).' OWNER TO '.self::identifier($session, $login));
        $session->statement('REVOKE ALL ON DATABASE '.self::identifier($session, $database).' FROM PUBLIC');
    }

    private function makeSpare(Connection $session, string $spare, string $login): void
    {
        if ($session->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$spare]) === null) {
            $session->statement('CREATE DATABASE '.self::identifier($session, $spare).' OWNER '.self::identifier($session, $login).' TEMPLATE template0 ENCODING \'UTF8\'');
        }

        $session->statement('REVOKE ALL ON DATABASE '.self::identifier($session, $spare).' FROM PUBLIC');
    }

    /**
     * Gives the login the tables, sequences and views in the site's public schema that the given user made, so a
     * migration run as the login can change them.
     */
    private function handOverTables(Connection $session, string $login): void
    {
        $session->select("SELECT set_config('teme.login', ?, false)", [$login]);
        $session->statement(<<<'SQL'
            DO $$
            DECLARE item record;
            BEGIN
                FOR item IN
                    SELECT c.relname, c.relkind
                    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'public' AND c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
                      AND c.relkind IN ('r', 'p', 'S', 'v', 'm')
                      AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype = 'a' AND d.classid = 'pg_class'::regclass)
                LOOP
                    EXECUTE format('ALTER %s %I.%I OWNER TO %I',
                        CASE item.relkind WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' ELSE 'TABLE' END,
                        'public', item.relname, current_setting('teme.login'));
                END LOOP;
            END $$
            SQL);
    }

    private static function identifier(Connection $session, string $name): string
    {
        return (string) $session->scalar('SELECT quote_ident(?)', [$name]);
    }

    private static function literal(Connection $session, #[SensitiveParameter] string $value): string
    {
        return (string) $session->scalar('SELECT quote_literal(?)', [$value]);
    }
}
