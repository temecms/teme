<?php

declare(strict_types=1);

use Tests\Fixtures\Deployment\DatabaseInit;
use Tests\Fixtures\Guides\OperatorGuide;

/*
 * The SQL the guide gives for a platform's own PostgreSQL, run on the test
 * server as a superuser would run it. No database trait: CREATE DATABASE
 * can't run inside a test's transaction.
 */

afterEach(function (): void {
    if (isset($this->names)) {
        DatabaseInit::drop($this->names);
    }
});

/**
 * The guide's SQL with the names a reader would choose swapped for the
 * test's own, as separate statements.
 *
 * @param  array{role: string, database: string, verify: string, password: string}  $names
 *
 * @return list<string>
 */
function guideDatabaseStatements(array $names): array
{
    $blocks = array_values(array_filter(OperatorGuide::page('database.md')->fences(), fn (array $fence): bool => $fence['lang'] === 'sql' && str_contains($fence['body'], 'CREATE ROLE')));

    expect($blocks)->toHaveCount(1);

    $sql = (string) preg_replace(
        ['/\bteme_verify\b/', '/\bteme\b/', "/'choose-a-long-random-password'/"],
        ['"'.$names['verify'].'"', '"'.$names['role'].'"', "'".$names['password']."'"],
        implode("\n", array_filter(explode("\n", $blocks[0]['body']), fn (string $line): bool => ! str_starts_with(trim($line), '--'))),
    );

    return array_values(array_filter(array_map(trim(...), explode(';', $sql)), fn (string $statement): bool => $statement !== ''));
}

it('makes, from the guide\'s own SQL, a role that is no superuser and owns both databases', function (): void {
    $this->names = DatabaseInit::names();

    foreach (guideDatabaseStatements($this->names) as $statement) {
        DatabaseInit::admin()->statement($statement);
    }

    $role = (array) DatabaseInit::admin()->selectOne('SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname = ?', [$this->names['role']]);
    $databases = DatabaseInit::admin()->select('SELECT datname, pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname IN (?, ?) ORDER BY datname', [$this->names['database'], $this->names['verify']]);

    expect($role)->toBe(['rolcanlogin' => true, 'rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolreplication' => false, 'rolbypassrls' => false])
        ->and(array_map(fn (object $row): array => (array) $row, $databases))->toBe([
            ['datname' => $this->names['database'], 'owner' => $this->names['role']],
            ['datname' => $this->names['verify'], 'owner' => $this->names['role']],
        ]);
});

it('gives that role the public schema of both databases, and no other role a way in', function (): void {
    $this->names = DatabaseInit::names();

    foreach (guideDatabaseStatements($this->names) as $statement) {
        DatabaseInit::admin()->statement($statement);
    }

    foreach ([$this->names['database'], $this->names['verify']] as $database) {
        $session = DatabaseInit::connectAs($this->names, $database);

        expect($session->scalar("SELECT pg_has_role(current_user, nspowner, 'USAGE') FROM pg_namespace WHERE nspname = 'public'"))->toBeTrue();
    }

    $public = DatabaseInit::admin()->select('SELECT datname FROM pg_database, aclexplode(datacl) AS grant_ WHERE datname IN (?, ?) AND grant_.grantee = 0', [$this->names['database'], $this->names['verify']]);

    expect($public)->toBe([]);
});
