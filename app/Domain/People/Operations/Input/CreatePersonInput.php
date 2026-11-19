<?php

/**
 * What `CreatePerson` needs: the person's name, the address slug and
 * biography when given, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CreatePersonInput implements OperationInput
{
    /**
     * @param  string|null  $slug  their author address's slug; made from the name when null
     */
    public function __construct(
        public ?string $actorId,
        public string $name,
        public ?string $slug = null,
        public ?string $bio = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
