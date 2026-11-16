<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Columns;

/**
 * Reads the clock PluginColumns uses to enforce the per-call and per-page
 * budgets; tests bind a stepping fake.
 */
interface ColumnClock
{
    /** Monotonic time in nanoseconds. */
    public function nanoseconds(): int;
}
