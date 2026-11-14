<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * What a run did (I-362).
 */
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
