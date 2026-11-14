<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What MarkVisibilityWaiting needs: the source, and who is importing (null
 * for the operator).
 */
final readonly class MarkVisibilityWaitingInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
