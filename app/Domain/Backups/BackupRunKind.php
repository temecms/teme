<?php

/**
 * What a run did (I-362).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum BackupRunKind: string
{
    case Backup = 'backup';
    case Copy = 'copy';
    case Check = 'check';
    case ReadBack = 'read-back';
    case TestRestore = 'test-restore';
    case Prune = 'prune';
    case Restore = 'restore';
}
