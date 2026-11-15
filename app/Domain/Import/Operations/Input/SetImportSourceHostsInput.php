<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `SetImportSourceHosts` needs: the import source, the other host
 * names to keep (or an empty list to clear them), and who sets them
 * (null for the operator).
 */
final readonly class SetImportSourceHostsInput implements OperationInput
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public string $sourceId,
        public array $hosts,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
