<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

/**
 * What ran after an Operation's commit, in order, for the delivery tests.
 * Bound scoped, never a static.
 */
final class Steps
{
    /** @var list<string> */
    private array $steps = [];

    public function add(string $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->steps;
    }
}
