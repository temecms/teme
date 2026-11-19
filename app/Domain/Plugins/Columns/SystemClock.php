<?php

/**
 * The real high-resolution clock for list-column budgets.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

final class SystemClock implements ColumnClock
{
    public function nanoseconds(): int
    {
        return (int) hrtime(true);
    }
}
