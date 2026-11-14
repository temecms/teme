<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

/**
 * How far a restore got, which decides what a stop leaves and says (docs/architecture/backups.md, Restoring).
 */
enum RestoreStage
{
    case SiteUntouched;
    case TablesDropped;
    case DatabaseRestored;
}
