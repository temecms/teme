<?php

/**
 * What `ChangePassword` needs: whose password, the current one, the new one
 * twice, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangePasswordInput implements OperationInput
{
    public function __construct(
        public string $userId,
        public string $currentPassword,
        public string $password,
        public string $passwordConfirmation,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
