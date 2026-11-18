<?php

/**
 * What `ResendInvitation` needs: whose invitation to send again, and who is
 * asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ResendInvitationInput implements OperationInput
{
    public function __construct(
        public string $userId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
