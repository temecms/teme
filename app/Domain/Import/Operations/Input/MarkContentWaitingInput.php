<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What MarkContentWaiting needs: the source, the run whose media phase may
 * have brought media, and who is importing (null for the operator).
 */
final readonly class MarkContentWaitingInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $runId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
