<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\RecoveryKey;
use Teme\Contracts\Operations\OperationInput;

/**
 * What ExtendRecoveryKey needs: the recovery key a person typed, and who is
 * asking.
 */
final readonly class ExtendRecoveryKeyInput implements OperationInput
{
    public function __construct(
        public RecoveryKey $recoveryKey,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
