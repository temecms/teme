<?php

/**
 * Where a tracked job stands: waiting for its first step, running, or ended as
 * finished, failed or cancelled (I-104).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs;

enum JobState: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Returns the state's name as people read it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Waiting => __('Waiting'),
            self::Running => __('Running'),
            self::Finished => __('Finished'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * Returns whether a job in this state has ended.
     */
    public function ended(): bool
    {
        return match ($this) {
            self::Finished, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
