<?php

/**
 * Says whether a host-run site's restore account is fit for the test restore
 * (TEME_BACKUPS=host, TEME_RESTORE_DB_USERNAME): signed in, able to create the
 * temporary database, not a superuser, and not the site's own role. Silent when
 * no restore account is named, since the backups' verdict says that already. It
 * reads the catalog only, and makes and drops nothing.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Backups\BackupMode;
use App\Domain\Backups\Verify\TemporaryDatabase;
use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

final readonly class RestoreAccount implements DoctorCheck
{
    public function title(): string
    {
        return 'Restore account';
    }

    /**
     * @return list<Finding>
     */
    public function examine(): array
    {
        if (! BackupMode::isHost() || ! TemporaryDatabase::configured()) {
            return [];
        }

        $role = (string) config('database.connections.'.TemporaryDatabase::CONNECTION.'.username');

        $findings = [];

        // The site's own role must stay NOCREATEDB: a restore account that is that role is a fault in itself.
        if ($role === (string) DB::connection()->getConfig('username')) {
            $findings[] = new Finding(Severity::Warning, 'TEME_RESTORE_DB_USERNAME is the site’s own role, which must stay NOCREATEDB, so the test restore can’t make its temporary database.', 'Name a separate role, with LOGIN NOSUPERUSER CREATEDB, as TEME_RESTORE_DB_USERNAME.');
        }

        try {
            $row = DB::connection(TemporaryDatabase::CONNECTION)->selectOne(<<<'SQL'
                SELECT CAST(r.rolsuper AS integer) AS superuser,
                       CAST(r.rolcreatedb AS integer) AS creates_databases
                FROM pg_catalog.pg_roles AS r
                WHERE r.rolname = current_user
                SQL);
        } catch (QueryException|PDOException) {
            return [new Finding(Severity::Warning, "Teme couldn’t sign in as the restore account {$role}, so the weekly test restore will fail.", 'Check TEME_RESTORE_DB_USERNAME, TEME_RESTORE_DB_PASSWORD and TEME_RESTORE_DB_HOST.')];
        } finally {
            DB::purge(TemporaryDatabase::CONNECTION);
        }

        if ((bool) $row?->superuser) {
            $findings[] = new Finding(Severity::Warning, "The restore account {$role} is a superuser, so a fault in the test restore could reach every database on the server.", "As a superuser: ALTER ROLE {$role} NOSUPERUSER;");
        }

        if (! (bool) $row?->creates_databases) {
            $findings[] = new Finding(Severity::Warning, "The restore account {$role} can't create databases, so the test restore can't make its temporary database.", "As a superuser: ALTER ROLE {$role} CREATEDB;");
        }

        return $findings;
    }
}
