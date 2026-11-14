<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Disabling a plugin, by its Composer name. A null actor is the operator at
 * the server's shell.
 */
final readonly class DisablePluginInput implements OperationInput
{
    public function __construct(
        public string $name,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
