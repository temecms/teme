<?php

/**
 * What `UpdatePerson` needs: the person, what changes (null leaves it as it
 * is), and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UpdatePersonInput implements OperationInput
{
    public function __construct(
        public ?string $actorId,
        public string $personId,
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $bio = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
