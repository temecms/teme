<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What BlockHost needs: the server's name as typed, why, and who is asking.
 */
final readonly class BlockHostInput implements OperationInput
{
    public function __construct(
        public string $host,
        public ?string $reason,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
