<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CancelExtensionRequest needs: the request, and who cancels it, or null for the operator.
 */
final readonly class CancelExtensionRequestInput implements OperationInput
{
    public function __construct(
        public string $requestId,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
