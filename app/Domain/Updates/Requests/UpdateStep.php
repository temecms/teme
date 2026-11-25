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

    /** The step's short name for the maintenance page. */
    public function label(): string
    {
        return match ($this) {
            self::Deploy => __('Deploying'),
            self::Web => __('Updating the web process'),
            self::Worker => __('Updating the worker'),
            self::WorkerLong => __('Updating the long worker'),
            self::Scheduler => __('Updating the scheduler'),
            self::Check => __('Checking the update'),
            self::Updater => __('Updating the updater'),
        };
    }

    public const string PENDING = 'pending';

    public const string STARTED = 'started';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string SWITCHED_BACK = 'switched-back';

    /** Every state a step is recorded in. */
    public const array STATES = [self::PENDING, self::STARTED, self::DONE, self::FAILED, self::SWITCHED_BACK];
}
