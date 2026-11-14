<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * What an import run sets out to do: bring a whole site across, or catch up
 * on what changed since the last finished run (I-345).
 */
enum ImportRunKind: string
{
    case Full = 'full';
    case CatchUp = 'catch-up';

    /**
     * The words people read for this kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full import',
            self::CatchUp => 'Catch-up',
        };
    }
}
