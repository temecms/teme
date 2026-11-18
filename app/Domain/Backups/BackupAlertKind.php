<?php

/**
 * What an alert email about the backups says (I-2409): something failed, it still fails a day on, or it works again.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum BackupAlertKind: string
{
    case Failing = 'failing';
    case Reminder = 'reminder';
    case Fixed = 'fixed';
}
