<?php

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RemoveField needs: the type, the key of the field it hides, and who is asking.
 */
final readonly class RemoveFieldInput implements OperationInput
{
    public function __construct(
        public string $type,
        public string $key,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
