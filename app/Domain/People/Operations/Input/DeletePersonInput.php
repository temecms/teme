<?php

declare(strict_types=1);

namespace App\Domain\People\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `DeletePerson` needs: the person, and who is asking.
 */
final readonly class DeletePersonInput implements OperationInput
{
    public function __construct(
        public ?string $actorId,
        public string $personId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
