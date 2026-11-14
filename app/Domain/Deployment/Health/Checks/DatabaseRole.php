<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Support\Facades\DB;

/**
 * Teme's database role has what it needs and no more (I-379): not a
 * superuser, unable to create roles or databases, and with its schema's
 * owner's rights over the schema and everything in it, so a migration can
 * change what an earlier one made.
 */
final readonly class DatabaseRole implements DoctorCheck
{
    public function title(): string
    {
        return 'Database role';
    }

    public function examine(): array
    {
        // pg_has_role(…, 'USAGE') is true for an owner, for a member of the
        // owning role, and for pg_database_owner's member, the database's
        // owner, which owns public from PostgreSQL 15.
        $row = DB::selectOne(<<<'SQL'
            SELECT r.rolname AS role,
                   CAST(r.rolsuper AS integer) AS superuser,
                   CAST(r.rolcreaterole AS integer) AS creates_roles,
                   CAST(r.rolcreatedb AS integer) AS creates_databases,
                   n.nspname AS schema_name,
                   pg_get_userbyid(n.nspowner) AS schema_owner,
                   CAST(pg_has_role(r.oid, n.nspowner, 'USAGE') AS integer) AS owns_schema,
                   others.total AS others_total,
                   others.earliest AS others_earliest
            FROM pg_roles AS r
            JOIN pg_namespace AS n ON n.nspname = current_schema()
            CROSS JOIN LATERAL (
                SELECT count(*) AS total, min(CAST(c.relname AS text)) AS earliest
                FROM pg_class AS c
                WHERE c.relnamespace = n.oid
                  AND c.relkind IN ('r', 'p', 'v', 'm', 'S')
                  AND NOT pg_has_role(r.oid, c.relowner, 'USAGE')
            ) AS others
            WHERE r.rolname = current_user
            SQL);

        if ($row === null) {
            return [new Finding(Severity::Error, "Teme's connection uses no schema that exists, so there is nothing to check.", "Make the schema that config/database.php's pgsql search_path names, owned by Teme's role.")];
        }

        $production = app()->isProduction();
        $role = (string) $row->role;
        $schema = (string) $row->schema_name;
        $owner = (string) $row->schema_owner;

        // A superuser has every right, so the rest says nothing more.
        if ((int) $row->superuser === 1) {
            return [new Finding(Severity::inProduction($production), "{$role} is a superuser, so a fault anywhere in Teme could reach every database on the server.", "Connect as a role made with LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE that owns Teme's database, and keep the superuser for the server's own work.")];
        }

        $findings = [];

        if ((int) $row->creates_roles === 1) {
            $findings[] = new Finding(Severity::inProduction($production), "{$role} can create roles, which Teme never needs.", "As a superuser: ALTER ROLE {$role} NOCREATEROLE;");
        }

        if ((int) $row->creates_databases === 1) {
            $findings[] = new Finding(Severity::Warning, "{$role} can create databases, which Teme never needs.", "As a superuser: ALTER ROLE {$role} NOCREATEDB;");
        }

        if ((int) $row->owns_schema !== 1) {
            $findings[] = new Finding(Severity::inProduction($production), "{$role} doesn't own the schema {$schema}, which belongs to {$owner}, so a migration can fail to change it.", "As a superuser: ALTER SCHEMA {$schema} OWNER TO {$role};");
        }

        $others = (int) $row->others_total;
        $earliest = (string) $row->others_earliest;

        if ($others === 1) {
            $findings[] = new Finding(Severity::inProduction($production), "{$earliest} in {$schema} belongs to another role, so a migration can't change it.", "As a superuser: ALTER TABLE {$schema}.{$earliest} OWNER TO {$role};");
        } elseif ($others > 1) {
            $findings[] = new Finding(Severity::inProduction($production), "{$others} tables and sequences in {$schema} belong to other roles, {$earliest} among them, so a migration can't change them.", "As a superuser, give each to {$role}, starting with ALTER TABLE {$schema}.{$earliest} OWNER TO {$role};");
        }

        return $findings !== [] ? $findings : [new Finding(Severity::Ok, "{$role} isn't a superuser, can't create roles or databases, and owns the schema {$schema} and everything in it.")];
    }
}
