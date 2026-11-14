<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

/**
 * How a teme:deploy ended, and the exit code a program reads for it
 * (I-3189): zero for a site at this release, and one code for each way it
 * stopped, telling whether anything changed.
 */
enum DeployOutcome: string
{
    case Deployed = 'deployed';
    case Current = 'current';
    case Failed = 'failed';
    case Refused = 'refused';
    case BackupFailed = 'backup-failed';
    case MigrationFailed = 'migration-failed';
    case Unfinished = 'unfinished';
    case Pending = 'pending';
    case Busy = 'busy';

    public function exitCode(): int
    {
        return match ($this) {
            self::Deployed, self::Current => 0,
            self::Failed => 1,
            self::Refused => 2,
            self::BackupFailed => 3,
            self::MigrationFailed => 4,
            self::Unfinished => 5,
            self::Pending => 6,
            self::Busy => 7,
        };
    }
}
