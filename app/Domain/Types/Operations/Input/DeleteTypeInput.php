<?php

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What DeleteType needs: the type's key, and who is asking (null for the operator).
 */
final readonly class DeleteTypeInput implements OperationInput
{
    public function __construct(
        public string $key,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
