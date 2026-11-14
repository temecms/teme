<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

/**
 * Teme's services in the Compose file, in the order an update watches them.
 */
enum Part: string
{
    case Deploy = 'deploy';
    case Web = 'web';
    case Worker = 'worker';
    case WorkerLong = 'worker-long';
    case Scheduler = 'scheduler';
    case Updater = 'updater';
}
