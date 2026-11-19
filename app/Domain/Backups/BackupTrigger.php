<?php

/**
 * What started a run: the schedule, a person, a deployment, or a command at the server.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum BackupTrigger: string
{
    case Schedule = 'schedule';
    case Person = 'person';
    case Deploy = 'deploy';
    case Command = 'command';
}
