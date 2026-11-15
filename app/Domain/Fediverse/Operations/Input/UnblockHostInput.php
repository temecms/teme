<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What UnblockHost needs: the blocked server's name, and who is asking.
 */
final readonly class UnblockHostInput implements OperationInput
{
    public function __construct(
        public string $host,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
