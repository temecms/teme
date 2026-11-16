<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `RequestPluginScript` needs: the plugin, what the script is called,
 * its exact text, and the person the request is made for.
 */
final readonly class RequestPluginScriptInput implements OperationInput
{
    public function __construct(
        public string $pluginCode,
        public string $name,
        public string $script,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
