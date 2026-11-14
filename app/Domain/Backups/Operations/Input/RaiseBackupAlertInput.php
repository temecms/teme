<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RaiseBackupAlert needs: who is asking, null for the operator.
 */
final readonly class RaiseBackupAlertInput implements OperationInput
{
    public function __construct(public ?string $actorId = null) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
