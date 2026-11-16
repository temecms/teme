<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What PruneFediverse needs: who asks, a person or, for none, the operator.
 */
final readonly class PruneFediverseInput implements OperationInput
{
    public function __construct(public ?string $actorId = null) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
