<?php

/**
 * What RequestBackup needs: who is asking, or null for the operator.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RequestBackupInput implements OperationInput
{
    public function __construct(public ?string $actorId = null) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
