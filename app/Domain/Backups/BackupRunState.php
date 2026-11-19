<?php

/**
 * Whether a run is still going, or how it ended.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum BackupRunState: string
{
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
}
