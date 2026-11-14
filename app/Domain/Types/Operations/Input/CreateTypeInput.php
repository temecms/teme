<?php

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use App\Domain\Types\TypeDefinition;
use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateType needs: the type, and who is asking (null for the operator).
 */
final readonly class CreateTypeInput implements OperationInput
{
    public function __construct(
        public TypeDefinition $type,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
