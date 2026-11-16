<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

/**
 * The real high-resolution clock for list-column budgets.
 */
final class SystemClock implements ColumnClock
{
    public function nanoseconds(): int
    {
        return (int) hrtime(true);
    }
}
