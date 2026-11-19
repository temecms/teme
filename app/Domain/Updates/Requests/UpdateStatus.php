<?php

/**
 * Where an update request stands: open while it waits or runs, then how it ended.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Requests;

enum UpdateStatus: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Done = 'done';
    case SwitchedBack = 'switched_back';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Whether the request still waits or runs. */
    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Running;
    }
}
