<?php

/**
 * What RemoveField needs: the type, the key of the field it hides, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RemoveFieldInput implements OperationInput
{
    public function __construct(
        public string $type,
        public string $key,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
