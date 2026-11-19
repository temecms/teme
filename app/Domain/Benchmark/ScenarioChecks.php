<?php

/**
 * What a scenario's measured run broke beyond its plans' own rules, in
 * plain words: a hit that read the database, a request that wrote to it, an
 * index its plans were to read and didn't, or more items than fit the time
 * something allows them (I-148).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark;

final class ScenarioChecks
{
    /**
     * Each promise the scenario made that its inspected run broke.
     *
     * @param  array{readsNothing?: bool, writesNothing?: bool, reads?: list<string>, within?: list<array{items: int, seconds: int, what: string}>}  $scenario
     * @param  list<string>  $indexes  the indexes its plans read
     *
     * @return list<string>
     */
    public static function problems(array $scenario, float $p95Ms, int $queries, int $writes, array $indexes): array
    {
        $problems = [];

        if (($scenario['readsNothing'] ?? false) && $queries + $writes > 0) {
            $problems[] = sprintf('A hit read the database: %d statements', $queries + $writes);
        }

        if (($scenario['writesNothing'] ?? false) && $writes > 0) {
            $problems[] = sprintf('A request wrote to the database: %d statements', $writes);
        }

        foreach ($scenario['reads'] ?? [] as $index) {
            if (! in_array($index, $indexes, true)) {
                $problems[] = "Read no {$index}";
            }
        }

        // How many of a per-item scenario's items fit the time something
        // allows them, such as what an Empty press deletes in its request.
        foreach ($scenario['within'] ?? [] as $limit) {
            $seconds = $p95Ms * $limit['items'] / 1000;

            if ($seconds > $limit['seconds']) {
                $problems[] = sprintf('%s items would take %.1f s, over %d s: %s', number_format($limit['items']), $seconds, $limit['seconds'], $limit['what']);
            }
        }

        return $problems;
    }
}
