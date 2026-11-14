<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

/**
 * What one page of Tools › AI activity's list asks for: the person, the task and the month (its first day) it is narrowed to, a cursor either way, and the page size.
 */
final readonly class AiActivityRequest
{
    public function __construct(
        public ?int $person = null,
        public ?string $task = null,
        public ?string $month = null,
        public ?string $after = null,
        public ?string $before = null,
        public int $perPage = 50,
    ) {}
}
