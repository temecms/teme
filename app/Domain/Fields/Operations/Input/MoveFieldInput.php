<?php

/**
 * What MoveField needs: the type, the field's key, the place it goes to among the type's fields (from 1), and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class MoveFieldInput implements OperationInput
{
    public function __construct(
        public string $type,
        public string $key,
        public int $position,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
