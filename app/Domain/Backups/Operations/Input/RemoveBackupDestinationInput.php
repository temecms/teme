<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RemoveBackupDestination needs: the place, and who is asking.
 */
final readonly class RemoveBackupDestinationInput implements OperationInput
{
    public function __construct(
        public string $placeId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
