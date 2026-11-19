<?php

/**
 * What `AcceptInvitation` needs: the address, the invitation token, the new
 * password twice, and the name the person chose. Nobody is signed in, so the
 * token is the authority.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AcceptInvitationInput implements OperationInput
{
    public function __construct(
        public string $email,
        public string $token,
        public string $password,
        public string $passwordConfirmation,
        /**
         * The name the person wants on what they write; null or blank keeps
         * the one they were invited by.
         *
         * @since next
         */
        public ?string $name = null,
    ) {}

    /**
     * Nobody is signed in: the invitation token is the authority.
     */
    public function actorId(): ?string
    {
        return null;
    }
}
