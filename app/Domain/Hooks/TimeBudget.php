<?php

declare(strict_types=1);

namespace App\Domain\Hooks;

use Closure;
use Throwable;

/**
 * The time a Transform hook's listeners may take on one page: each call
 * at most $callMs, and all of them together at most $pageMs, kept across
 * every ask on the page (docs/plans/plugin-sockets-3.md). A listener over
 * its call's budget, or reached once the page's is spent, is left out.
 */
final class TimeBudget
{
    /** Nanoseconds the page's listeners have taken so far. */
    private int $spent = 0;

    /**
     * @param  Closure(): int  $clock  monotonic time in nanoseconds
     * @param  Closure(string, int, int): Throwable  $tooSlow  the failure recorded for an owner, from its milliseconds and the budget it went over
     */
    public function __construct(
        private readonly int $callMs,
        private readonly int $pageMs,
        private readonly Closure $clock,
        private readonly Closure $tooSlow,
    ) {}

    /**
     * Returns the failure to record for this owner when the page's budget
     * is already spent, or null when it may be asked.
     */
    public function spentBefore(string $owner): ?Throwable
    {
        return $this->spent >= $this->pageMs * 1_000_000 ? ($this->tooSlow)($owner, intdiv($this->spent, 1_000_000), $this->pageMs) : null;
    }

    /**
     * Returns the time now, for charge() once the listener returns.
     */
    public function start(): int
    {
        return ($this->clock)();
    }

    /**
     * Adds the time since $started to the page's, and returns the failure to
     * record when this call went over its own budget, or null.
     */
    public function charge(string $owner, int $started): ?Throwable
    {
        $took = ($this->clock)() - $started;
        $this->spent += $took;

        return $took > $this->callMs * 1_000_000 ? ($this->tooSlow)($owner, intdiv($took, 1_000_000), $this->callMs) : null;
    }
}
