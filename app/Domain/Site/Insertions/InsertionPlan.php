<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

/**
 * Where cleaned insertion HTML sits in a document: each top-level index
 * maps to the markup drawn before that node (count of content means after
 * the last). Built by PlanBuilder for DocumentRenderer.
 */
final readonly class InsertionPlan
{
    /**
     * @param  array<int, list<string>>  $at  index => cleaned HTML fragments
     * @param  array<string, list<string>>  $origins  CSP directive => https origins
     */
    public function __construct(
        private array $at,
        public array $origins = [],
    ) {}

    /**
     * The markup drawn before each top-level index.
     *
     * @return array<int, list<string>>
     */
    public function at(): array
    {
        return $this->at;
    }

    /**
     * Whether any insertion will be drawn.
     */
    public function empty(): bool
    {
        return $this->at === [];
    }
}
