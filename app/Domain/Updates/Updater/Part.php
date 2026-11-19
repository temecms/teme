<?php

/**
 * Teme's services in the Compose file, in the order an update watches them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

enum Part: string
{
    case Deploy = 'deploy';
    case Web = 'web';
    case Worker = 'worker';
    case WorkerLong = 'worker-long';
    case Scheduler = 'scheduler';
    case Updater = 'updater';
}
