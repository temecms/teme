<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordBulkRunStep needs: the run, the cursor the step read its page
 * from and the cursor past it, the rows it handled, what it skipped by
 * reason, the first refusal's words, and the run's person.
 */
final readonly class RecordBulkRunStepInput implements OperationInput
{
    /**
     * @param  string|null  $from  the run's cursor as the step found it; null at the top
     * @param  string|null  $after  the cursor past this step's page; null on the last page
     * @param  array<string, int>  $skipped  the count for each reason
     */
    public function __construct(
        public string $runId,
        public ?string $from,
        public ?string $after,
        public int $handled,
        public array $skipped,
        public ?string $refusal,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
