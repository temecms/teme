<?php

/**
 * The sentences a run says as each step starts, each with when it was said,
 * so a run's steps are timed without changing the run.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark\BackupBench;

use Closure;

final class StepClock
{
    /** @var list<array{step: string, at: float}> */
    private array $said = [];

    /**
     * The callback a run is given.
     *
     * @return Closure(string): void
     */
    public function say(): Closure
    {
        return function (string $step): void {
            $this->said[] = ['step' => $step, 'at' => microtime(true)];
        };
    }

    /**
     * When the first sentence was said, or null when none was.
     */
    public function firstAt(): ?float
    {
        return $this->said[0]['at'] ?? null;
    }

    /**
     * Each sentence and its seconds to the next, or to $end, one decimal.
     *
     * @return list<array{step: string, seconds: float}>
     */
    public function steps(float $end): array
    {
        $steps = [];

        foreach ($this->said as $index => $line) {
            $next = $this->said[$index + 1]['at'] ?? $end;
            $steps[] = ['step' => $line['step'], 'seconds' => round($next - $line['at'], 1)];
        }

        return $steps;
    }
}
