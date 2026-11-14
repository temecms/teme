<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * What a ReencryptSecrets run did, one entry per registered column in the
 * registry's order (I-359). It carries counts and row keys only, so it is
 * safe to report, log or print.
 */
final readonly class ReencryptedSecrets
{
    /**
     * @param  list<ReencryptedColumn>  $columns
     */
    public function __construct(public array $columns) {}

    /**
     * How many values were written again under this server's key.
     */
    public function rewritten(): int
    {
        return array_sum(array_map(fn (ReencryptedColumn $column): int => $column->rewritten, $this->columns));
    }

    /**
     * How many values were already under this server's key.
     */
    public function current(): int
    {
        return array_sum(array_map(fn (ReencryptedColumn $column): int => $column->current, $this->columns));
    }

    /**
     * How many values no key opened and the run left as they were.
     */
    public function unopenedCount(): int
    {
        return array_sum(array_map(fn (ReencryptedColumn $column): int => count($column->unopened), $this->columns));
    }

    /**
     * Whether every value now opens with this server's key.
     */
    public function isComplete(): bool
    {
        return $this->unopenedCount() === 0;
    }

    /**
     * One report line per column, in the registry's order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(fn (ReencryptedColumn $column): string => $column->line(), $this->columns);
    }

    /**
     * Whether a value no key opened belongs to a person's two-factor sign-in.
     */
    public function touchesTwoFactor(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->column->table === 'users' && $column->unopened !== []) {
                return true;
            }
        }

        return false;
    }
}
