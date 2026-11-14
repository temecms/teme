<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * No actor: whoever holds a valid reset token is authorised, and nobody else.
 */
final readonly class ResetPasswordInput implements OperationInput
{
    public function __construct(
        public string $userId,
        public string $token,
        public string $password,
        public string $passwordConfirmation,
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
