<?php

/**
 * Reads the clock PluginColumns uses to enforce the per-call and per-page
 * budgets; tests bind a stepping fake.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

interface ColumnClock
{
    /** Monotonic time in nanoseconds. */
    public function nanoseconds(): int;
}
