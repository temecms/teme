<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ReplaceRecoveryKey needs: the label of the key to replace, and who is
 * asking.
 */
final readonly class ReplaceRecoveryKeyInput implements OperationInput
{
    public function __construct(
        public string $label,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
