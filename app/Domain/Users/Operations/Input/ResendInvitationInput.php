<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `ResendInvitation` needs: whose invitation to send again, and who is
 * asking.
 */
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
