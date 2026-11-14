<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Where an import run stands: carried out by the queue now, stopped and
 * waiting to be carried on, or finished (I-332).
 */
enum ImportRunState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Finished = 'finished';

    /**
     * The words people read for this state.
     */
    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Stopped => 'Stopped',
            self::Finished => 'Finished',
        };
    }
}
