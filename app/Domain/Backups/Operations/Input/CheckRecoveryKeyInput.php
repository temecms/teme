<?php

/**
 * What CheckRecoveryKey needs: the recovery key a person typed, and who is
 * asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\RecoveryKey;
use Teme\Contracts\Operations\OperationInput;

final readonly class CheckRecoveryKeyInput implements OperationInput
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
