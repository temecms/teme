<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Enabling a plugin: its Composer name, and the version, capabilities,
 * hosts and hooks the person was shown and agrees to. A null actor is the
 * operator at the server's shell, who names the administrator its schedules
 * run as (docs/proposals/plugin-sockets-1.md).
 */
final readonly class EnablePluginInput implements OperationInput
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @param  list<string>  $hooks
     * @param  string|null  $schedulesAs  the ID of the administrator its schedules run as, when the operator enables it
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $capabilities,
        public array $hosts,
        public array $hooks,
        public ?string $actorId,
        public ?string $schedulesAs = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
