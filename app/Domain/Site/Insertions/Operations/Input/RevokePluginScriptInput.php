<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `RevokePluginScript` needs: the plugin's script and who is deciding.
 */
final readonly class RevokePluginScriptInput implements OperationInput
{
    public function __construct(
        public string $scriptId,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
