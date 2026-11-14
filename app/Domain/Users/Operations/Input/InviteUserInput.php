<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `InviteUser` needs: the new person's name, address and role, who is
 * asking, and whether to email the link or leave it for the operator to print.
 */
final readonly class InviteUserInput implements OperationInput
{
    /**
     * @param  bool  $deliver  false when the operator prints the link instead
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $role,
        public ?string $actorId,
        public bool $deliver = true,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
