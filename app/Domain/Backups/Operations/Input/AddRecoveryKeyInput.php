<?php

/**
 * What AddRecoveryKey needs: the new key's label, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AddRecoveryKeyInput implements OperationInput
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
