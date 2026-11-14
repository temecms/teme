<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A request to cancel, or a suggestion to put aside, and who is asking.
 */
final readonly class DiscardSuggestionInput implements OperationInput
{
    public function __construct(
        public string $id,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
