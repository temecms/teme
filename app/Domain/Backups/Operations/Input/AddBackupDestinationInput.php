<?php

/**
 * What AddBackupDestination needs: the new place, a recovery key typed once
 * to lock it with, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\NewPlace;
use App\Domain\Backups\RecoveryKey;
use Teme\Contracts\Operations\OperationInput;

final readonly class AddBackupDestinationInput implements OperationInput
{
    public function __construct(
        public NewPlace $place,
        public RecoveryKey $recoveryKey,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
