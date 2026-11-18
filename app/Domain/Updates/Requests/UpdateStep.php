<?php

/**
 * The seven steps of an update, in the order the updater takes them, and the states a step is recorded in.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Requests;

enum UpdateStep: string
{
    case Deploy = 'deploy';
    case Web = 'web';
    case Worker = 'worker';
    case WorkerLong = 'worker-long';
    case Scheduler = 'scheduler';
    case Check = 'check';
    case Updater = 'updater';

    public const string PENDING = 'pending';

    public const string STARTED = 'started';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string SWITCHED_BACK = 'switched-back';

    /** Every state a step is recorded in. */
    public const array STATES = [self::PENDING, self::STARTED, self::DONE, self::FAILED, self::SWITCHED_BACK];
}
