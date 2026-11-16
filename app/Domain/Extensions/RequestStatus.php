<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/**
 * Where an extension request stands: open while it waits, runs or waits for
 * the administrator's agreement, then how it ended.
 */
enum RequestStatus: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Waiting = 'waiting';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Whether the request is still open: only one may be at a time. */
    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Running || $this === self::Waiting;
    }
}
