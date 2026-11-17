<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RunPluginAction needs: the plugin's code, the action's id, the
 * person who clicked it, and, from a plugin's own area, the area's permission.
 */
final readonly class RunPluginActionInput implements OperationInput
{
    public function __construct(
        public string $code,
        public string $action,
        public string $actorId,
        public ?string $permission = null,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
