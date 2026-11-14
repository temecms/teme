<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * What started a run: the schedule, a person, a deployment, or a command at the server.
 */
enum BackupTrigger: string
{
    case Schedule = 'schedule';
    case Person = 'person';
    case Deploy = 'deploy';
    case Command = 'command';
}
