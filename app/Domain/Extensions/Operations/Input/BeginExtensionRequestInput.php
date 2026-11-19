<?php

/**
 * What BeginExtensionRequest needs: the request the installer takes up, or
 * none, to begin a rebuild Teme asks for itself. Only the operator, the
 * installer, runs it, so the actor is none.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class BeginExtensionRequestInput implements OperationInput
{
    public function __construct(
        public ?string $requestId = null,
        public bool $rebuild = false,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
