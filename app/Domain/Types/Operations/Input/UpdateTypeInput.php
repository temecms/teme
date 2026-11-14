<?php

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use App\Domain\Types\TypeDefinition;
use Teme\Contracts\Operations\OperationInput;

/**
 * What UpdateType needs: the type (its key naming the row), and who is asking.
 */
final readonly class UpdateTypeInput implements OperationInput
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
