<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Agreeing to let a plugin reach hosts a person entered in its settings:
 * its Composer name, the hosts, and the person agreeing.
 */
final readonly class AgreePluginHostsInput implements OperationInput
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public string $name,
        public array $hosts,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
