<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Which of a repository's keys a row records: the machine key Teme's own runs use, or a recovery key a person holds (I-359).
 */
enum BackupKeyRole: string
{
    case Machine = 'machine';
    case Recovery = 'recovery';
}
