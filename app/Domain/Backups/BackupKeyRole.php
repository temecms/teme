<?php

/**
 * Which of a repository's keys a row records: the machine key Teme's own runs use, or a recovery key a person holds (I-359).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum BackupKeyRole: string
{
    case Machine = 'machine';
    case Recovery = 'recovery';
}
