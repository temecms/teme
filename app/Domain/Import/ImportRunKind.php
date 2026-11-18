<?php

/**
 * What an import run sets out to do: bring a whole site across, or catch up
 * on what changed since the last finished run (I-345).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

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
