<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * A place in use and answering, in use and failing, or no longer used.
 */
enum BackupDestinationState: string
{
    case Ready = 'ready';
    case Failing = 'failing';
    case Removed = 'removed';
}
