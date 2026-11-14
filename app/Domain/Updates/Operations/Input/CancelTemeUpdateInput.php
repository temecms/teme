<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CancelTemeUpdate needs: the request's ID and who cancels it, or null for the operator.
 */
final readonly class CancelTemeUpdateInput implements OperationInput
{
    public function __construct(
        public string $updateId,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
