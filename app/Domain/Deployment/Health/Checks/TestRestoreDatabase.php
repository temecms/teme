<?php

/**
 * Says whether the weekly test restore has its spare database (I-2406):
 * named, reachable, not the site's own, and owned by the role Teme connects
 * to it as. Always a warning: without it the test is skipped, and a deploy
 * mustn't stop for that. It reads the catalog only, and empties nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

final readonly class TestRestoreDatabase implements DoctorCheck
{
    public function title(): string
    {
        return 'Test restore database';
    }

    public function examine(): array
    {
        $role = (string) config('database.connections.'.SpareDatabase::CONNECTION.'.username');

        if (! SpareDatabase::isNamed()) {
            return [new Finding(Severity::Warning, 'No spare database is named, so the weekly test restore is skipped.', "As a superuser: CREATE DATABASE teme_verify OWNER {$role} TEMPLATE template0; then set TEME_VERIFY_DATABASE=teme_verify.")];
        }

        $name = (string) config('database.connections.'.SpareDatabase::CONNECTION.'.database');

        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || ! str_ends_with($name, SpareDatabase::SUFFIX)) {
            return [new Finding(Severity::Warning, "TEME_VERIFY_DATABASE is {$name}, but Teme test-restores only into a database whose name ends in _verify and holds only letters, digits, dots, hyphens and underscores.", 'Name a database that ends in _verify, such as teme_verify.')];
        }

        try {
            $session = DB::connection(SpareDatabase::CONNECTION);
            $row = $session->selectOne(<<<'SQL'
                SELECT current_user AS role,
                       pg_get_userbyid(d.datdba) AS owner,
                       CAST(pg_has_role(current_user, d.datdba, 'USAGE') AS integer) AS owns
                FROM pg_catalog.pg_database AS d
                WHERE d.datname = current_database()
                SQL);
            $isLive = SpareDatabase::sameDatabase($session, DB::connection());
        } catch (QueryException|PDOException) {
            return [new Finding(Severity::Warning, "Teme couldn't reach the spare database {$name}, so the weekly test restore will fail.", "If it doesn't exist, as a superuser: CREATE DATABASE {$name} OWNER {$role} TEMPLATE template0;")];
        } finally {
            DB::purge(SpareDatabase::CONNECTION);
        }

        if ($isLive) {
            return [new Finding(Severity::Warning, SpareDatabase::LIVE, 'Name a database of its own, such as teme_verify.')];
        }

        $connected = (string) $row->role;

        if ((int) $row->owns !== 1) {
            return [new Finding(Severity::Warning, "{$name} belongs to {$row->owner}, not {$connected}, so the weekly test restore can't empty it.", "As a superuser: ALTER DATABASE {$name} OWNER TO {$connected};")];
        }

        return [new Finding(Severity::Ok, "{$name} is ready for the weekly test restore, owned by {$connected}.")];
    }
}
