<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Enabling a plugin: its Composer name, and the version, capabilities,
 * hosts and hooks the person was shown and agrees to. A null actor is the
 * operator at the server's shell.
 */
final readonly class EnablePluginInput implements OperationInput
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @param  list<string>  $hooks
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $capabilities,
        public array $hosts,
        public array $hooks,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
