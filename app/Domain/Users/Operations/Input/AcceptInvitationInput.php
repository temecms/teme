<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `AcceptInvitation` needs: the address, the invitation token and the new
 * password twice. Nobody is signed in, so the token is the authority.
 */
final readonly class AcceptInvitationInput implements OperationInput
{
    public function __construct(
        public string $email,
        public string $token,
        public string $password,
        public string $passwordConfirmation,
    ) {}

    /**
     * Nobody is signed in: the invitation token is the authority.
     */
    public function actorId(): ?string
    {
        return null;
    }
}
