<?php

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Input;

use App\Domain\Fields\Stored\FieldDefinition;
use Teme\Contracts\Operations\OperationInput;

/**
 * What AddField needs: the type, the field as the sheet gave it (its key not made yet), and who is asking.
 */
final readonly class AddFieldInput implements OperationInput
{
    public function __construct(
        public string $type,
        public FieldDefinition $definition,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
