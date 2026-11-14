<?php

declare(strict_types=1);

namespace App\Domain\Benchmark;

/**
 * Reads a PostgreSQL EXPLAIN plan and names what breaks the scale rules: a
 * sequential scan on a large table, or — where an index should give the
 * order — a sort of more than one page of rows (I-148).
 */
final class PlanInspector
{
    private const int LARGE_TABLE = 10_000;

    private const int PAGE_ROWS = 51;

    /**
     * Everything in the plan that breaks a rule, in plain words.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, int>  $tableSizes
     *
     * @return list<string>
     */
    public function problems(array $plan, array $tableSizes, bool $expectsIndexOrder): array
    {
        $root = $plan[0]['Plan'] ?? [];
        $problems = [];
        $this->walk($root, $tableSizes, $expectsIndexOrder && $this->hasLimit($root), $problems);

        return $problems;
    }

    /**
     * The indexes the plan reads, each once, in the order met.
     *
     * @param  array<int, array<string, mixed>>  $plan
     *
     * @return list<string>
     */
    public function indexes(array $plan): array
    {
        $found = [];
        $this->collect($plan[0]['Plan'] ?? [], $found);

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $found
     */
    private function collect(array $node, array &$found): void
    {
        if (isset($node['Index Name'])) {
            $found[] = (string) $node['Index Name'];
        }

        foreach ((array) ($node['Plans'] ?? []) as $child) {
            $this->collect((array) $child, $found);
        }
    }

    /**
     * A statement that pages carries a LIMIT. Lookups keyed by one page's IDs
     * are bounded by the page size, whatever the table size, so the sort rule
     * does not apply to them.
     *
     * @param  array<string, mixed>  $node
     */
    private function hasLimit(array $node): bool
    {
        if (($node['Node Type'] ?? '') === 'Limit') {
            return true;
        }

        foreach ((array) ($node['Plans'] ?? []) as $child) {
            if ($this->hasLimit((array) $child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, int>  $tableSizes
     * @param  list<string>  $problems
     */
    private function walk(array $node, array $tableSizes, bool $expectsIndexOrder, array &$problems): void
    {
        $type = (string) ($node['Node Type'] ?? '');
        $relation = (string) ($node['Relation Name'] ?? '');
        $rows = (int) ($node['Plan Rows'] ?? 0);

        if ($type === 'Seq Scan' && ($tableSizes[$relation] ?? 0) > self::LARGE_TABLE) {
            $problems[] = sprintf('Sequential scan on %s (%s rows)', $relation, number_format($tableSizes[$relation]));
        }

        if ($expectsIndexOrder && in_array($type, ['Sort', 'Incremental Sort'], true) && $rows > self::PAGE_ROWS) {
            $problems[] = sprintf('Sort of %s rows where the index should give the order', number_format($rows));
        }

        foreach ((array) ($node['Plans'] ?? []) as $child) {
            $this->walk((array) $child, $tableSizes, $expectsIndexOrder, $problems);
        }
    }
}
