<?php

/**
 * What AgreeToExtensionRequest needs: the request, exactly what the person was shown and agreed to (the
 * request's needs as the screen read them), and who agrees, or null for the operator.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AgreeToExtensionRequestInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $needs
     */
    public function __construct(
        public string $requestId,
        public array $needs,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
