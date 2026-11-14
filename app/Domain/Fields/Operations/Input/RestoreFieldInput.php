<?php

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RestoreField needs: the type, the key of the removed field it brings back, and who is asking.
 */
final readonly class RestoreFieldInput implements OperationInput
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
