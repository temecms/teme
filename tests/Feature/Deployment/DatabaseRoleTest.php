<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Checks\DatabaseRole;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Deployment\RestrictedRole;

uses(RefreshDatabase::class);

afterEach(function (): void {
    if (isset($this->role)) {
        $this->role->close();
    }
});

it('finds nothing wrong with a role like production\'s, which owns public through pg_database_owner (I-379)', function (): void {
    $this->role = RestrictedRole::open();
    DB::statement('CREATE TABLE notes (id bigserial PRIMARY KEY)');
    app()->instance('env', 'production');

    expect(DB::selectOne("SELECT pg_get_userbyid(nspowner) AS owner FROM pg_namespace WHERE nspname = 'public'")->owner)->toBe('pg_database_owner')
        ->and(app(DatabaseRole::class)->examine())->toEqual([new Finding(Severity::Ok, "{$this->role->role} isn't a superuser, can't create roles or databases, and owns the schema public and everything in it.")]);
});

it('says a superuser is one, an error in production and a warning elsewhere, and nothing more', function (): void {
    // The sandbox's own role, which scripts/cloud-setup.sh makes a superuser.
    $self = DB::selectOne('SELECT current_user AS name, CAST(rolsuper AS integer) AS superuser FROM pg_roles WHERE rolname = current_user');
    $message = "{$self->name} is a superuser, so a fault anywhere in Teme could reach every database on the server.";
    $fix = "Connect as a role made with LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE that owns Teme's database, and keep the superuser for the server's own work.";

    expect($self->superuser)->toBe(1)
        ->and(app(DatabaseRole::class)->examine())->toEqual([new Finding(Severity::Warning, $message, $fix)]);

    app()->instance('env', 'production');

    expect(app(DatabaseRole::class)->examine())->toEqual([new Finding(Severity::Error, $message, $fix)]);
});

it('says what a role may create that Teme never needs', function (string $right, Severity $severity, string $message, string $fix): void {
    $this->role = RestrictedRole::open([$right]);
    app()->instance('env', 'production');
    $role = $this->role->role;

    expect(app(DatabaseRole::class)->examine())->toEqual([new Finding($severity, str_replace('{role}', $role, $message), str_replace('{role}', $role, $fix))]);
})->with([
    'roles, an error in production' => ['CREATEROLE', Severity::Error, '{role} can create roles, which Teme never needs.', 'As a superuser: ALTER ROLE {role} NOCREATEROLE;'],
    'databases, a warning' => ['CREATEDB', Severity::Warning, '{role} can create databases, which Teme never needs.', 'As a superuser: ALTER ROLE {role} NOCREATEDB;'],
]);

it('says a role that doesn\'t own its schema doesn\'t, naming the owner', function (): void {
    $this->role = RestrictedRole::open(ownsDatabase: false);
    app()->instance('env', 'production');
    $role = $this->role->role;

    expect(app(DatabaseRole::class)->examine())->toEqual([new Finding(Severity::Error, "{$role} doesn't own the schema public, which belongs to pg_database_owner, so a migration can fail to change it.", "As a superuser: ALTER SCHEMA public OWNER TO {$role};")]);
});

it('names a table in the schema that another role owns, and counts more than one', function (): void {
    $this->role = RestrictedRole::open();
    $role = $this->role->role;
    $this->role->tableOwnedByAnother('legacy_notes');

    expect(app(DatabaseRole::class)->examine())->toEqual([new Finding(Severity::Warning, "legacy_notes in public belongs to another role, so a migration can't change it.", "As a superuser: ALTER TABLE public.legacy_notes OWNER TO {$role};")]);

    $this->role->tableOwnedByAnother('archive_notes');

    expect(app(DatabaseRole::class)->examine())->toEqual([new Finding(Severity::Warning, "2 tables and sequences in public belong to other roles, archive_notes among them, so a migration can't change them.", "As a superuser, give each to {$role}, starting with ALTER TABLE public.archive_notes OWNER TO {$role};")]);
});
