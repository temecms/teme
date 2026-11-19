<?php

/**
 * How far a restore got, which decides what a stop leaves and says (docs/architecture/backups.md, Restoring).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

enum RestoreStage
{
    case SiteUntouched;
    case TablesDropped;
    case DatabaseRestored;
}
