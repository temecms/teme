<?php

/**
 * A place in use and answering, in use and failing, or no longer used.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum BackupDestinationState: string
{
    case Ready = 'ready';
    case Failing = 'failing';
    case Removed = 'removed';
}
