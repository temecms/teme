<?php

/**
 * What CancelExtensionRequest needs: the request, and who cancels it, or null for the operator.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
