<?php

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

/**
 * What every move of the pages under a page did in this request or job
 * (I-3363), for the admin's message once the Operation has returned; bound
 * scoped, so it starts empty for each.
 */
final class SubtreeMoves
{
    /** @var list<SubtreeOutcome> */
    private array $outcomes = [];

    /**
     * Adds what one move did; an empty one is left out.
     */
    public function add(SubtreeOutcome $outcome): void
    {
        if (! $outcome->isEmpty()) {
            $this->outcomes[] = $outcome;
        }
    }

    /**
     * Returns every outcome added, in order.
     *
     * @return list<SubtreeOutcome>
     */
    public function all(): array
    {
        return $this->outcomes;
    }

    /**
     * Returns every page kept at its old address, in order.
     *
     * @return list<array{title: string, path: string, address: string, holder: string}>
     */
    public function kept(): array
    {
        return array_merge([], ...array_map(fn (SubtreeOutcome $outcome): array => $outcome->kept, $this->outcomes));
    }
}
