<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Whether a run is still going, or how it ended.
 */
enum BackupRunState: string
{
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
}
