<?php

/**
 * No actor: whoever holds a valid reset token is authorised, and nobody else.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
