<?php

/**
 * What `DeletePerson` needs: the person, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
