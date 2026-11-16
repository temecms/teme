<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RemoveExtensionSource needs: the saved source, and who removes it, or null for the operator.
 */
final readonly class RemoveExtensionSourceInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
