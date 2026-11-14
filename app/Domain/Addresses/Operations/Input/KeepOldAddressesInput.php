<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What KeepOldAddresses needs: each old address, in the path table's form, with the item, term or author it leads to, and who is asking.
 */
final readonly class KeepOldAddressesInput implements OperationInput
{
    /**
     * @param  list<array{path: string, type: string, id: string}>  $addresses
     */
    public function __construct(
        public array $addresses,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
