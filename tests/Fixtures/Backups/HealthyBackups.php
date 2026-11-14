<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupKeyRole;

/**
 * Backups that give the Status check nothing to say, for tests of other
 * banners: set up at a place off this server, a finished backup, and a
 * spare database named. Rows only; nothing runs.
 */
final class HealthyBackups
{
    public static function seed(): void
    {
        $place = BackupRecords::destination(['kind' => BackupDestinationKind::S3]);
        BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
        BackupRecords::run();
        config(['database.connections.teme_verify.database' => 'teme_verify']);
    }
}
