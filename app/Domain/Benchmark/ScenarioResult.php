<?php

/**
 * One benchmark scenario's outcome: its 95th-percentile time against its
 * budget, any query-plan rule it broke, and what its inspected run sent:
 * SELECTs, the statements that wrote, and commands to the page store's Valkey,
 * and the indexes its plans read.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark;

final readonly class ScenarioResult
{
    /**
     * @param  list<string>  $problems
     * @param  list<string>  $indexes
     */
    public function __construct(
        public string $name,
        public string $group,
        public int $budgetMs,
        public float $p95Ms,
        public array $problems,
        public int $queries,
        public int $writes = 0,
        public int $commands = 0,
        public array $indexes = [],
    ) {}

    public function passed(): bool
    {
        return $this->p95Ms <= $this->budgetMs && $this->problems === [];
    }
}
