<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Fixtures\Deployment\DatabaseInit;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The recipe's database init (I-379), its SQL run on the test server as
 * psql sends it. No database trait: nothing here touches the default
 * connection, and CREATE DATABASE can't run inside a test's transaction.
 */

afterEach(function (): void {
    if (isset($this->names)) {
        DatabaseInit::drop($this->names);
    }
});

it('makes a role that logs in and owns both its databases and their public schema, and is no superuser', function (): void {
    $this->names = DatabaseInit::names();
    DatabaseInit::run($this->names);

    $role = (array) DatabaseInit::admin()->selectOne('SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = ?', [$this->names['role']]);
    $databases = DatabaseInit::admin()->select('SELECT datname, pg_get_userbyid(datdba) AS owner, pg_encoding_to_char(encoding) AS encoding FROM pg_database WHERE datname IN (?, ?) ORDER BY datname', [$this->names['database'], $this->names['verify']]);

    expect($role)->toBe(['rolcanlogin' => true, 'rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolreplication' => false, 'rolbypassrls' => false])
        ->and(array_map(fn (object $row): array => (array) $row, $databases))->toBe([
            ['datname' => $this->names['database'], 'owner' => $this->names['role'], 'encoding' => 'UTF8'],
            ['datname' => $this->names['verify'], 'owner' => $this->names['role'], 'encoding' => 'UTF8'],
        ]);

    foreach ([$this->names['database'], $this->names['verify']] as $database) {
        $session = DatabaseInit::connectAs($this->names, $database);

        // PostgreSQL 15 and later give public to pg_database_owner, which the database's owner holds.
        expect($session->scalar("SELECT nspowner::regrole::text FROM pg_namespace WHERE nspname = 'public'"))->toBe('pg_database_owner')
            ->and($session->scalar("SELECT pg_has_role(current_user, nspowner, 'USAGE') FROM pg_namespace WHERE nspname = 'public'"))->toBeTrue();

        $session->statement('CREATE TABLE teme_initdb_probe (id integer)');

        expect($session->scalar("SELECT to_regclass('public.teme_initdb_probe') IS NOT NULL"))->toBeTrue();
    }
});

it('gives the role no right to make a database or a role', function (): void {
    $this->names = DatabaseInit::names();
    DatabaseInit::run($this->names);
    $session = DatabaseInit::connectAs($this->names, $this->names['database']);

    expect(fn () => $session->statement('CREATE DATABASE teme_initdb_never'))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $session->statement('CREATE ROLE teme_initdb_never'))->toThrow(QueryException::class, 'permission denied');
});

it('lets no other role connect to its databases', function (): void {
    $this->names = DatabaseInit::names();
    DatabaseInit::run($this->names);
    $names = [$this->names['database'], $this->names['verify']];

    $defaults = DatabaseInit::admin()->select('SELECT datname FROM pg_database WHERE datname IN (?, ?) AND datacl IS NULL', $names);
    $public = DatabaseInit::admin()->select('SELECT datname FROM pg_database, aclexplode(datacl) AS grant_ WHERE datname IN (?, ?) AND grant_.grantee = 0', $names);

    expect($defaults)->toBe([])
        ->and($public)->toBe([]);
});

it('reads the password from its file as Teme does, and never puts it on a command line', function (): void {
    $script = ImageFiles::text('docker/postgres/initdb.d/10-teme-role.sh');

    expect($script)->toContain('TEME_DB_PASSWORD="$(cat "${DB_PASSWORD_FILE:?')
        ->and($script)->toContain('TEME_DB_PASSWORD="${TEME_DB_PASSWORD%.}"')
        ->and($script)->toContain("    *\"\$newline\")\n        TEME_DB_PASSWORD=\"\${TEME_DB_PASSWORD%\"\$newline\"}\"\n        TEME_DB_PASSWORD=\"\${TEME_DB_PASSWORD%\"\$carriage_return\"}\"\n")
        ->and($script)->toContain('export TEME_DB_PASSWORD')
        ->and(DatabaseInit::sql())->toStartWith("\\getenv teme_password TEME_DB_PASSWORD\n")
        ->and(DatabaseInit::sql())->toContain("PASSWORD :'teme_password';")
        ->and($script)->not->toMatch('/--set[= ]teme_password/');
});
