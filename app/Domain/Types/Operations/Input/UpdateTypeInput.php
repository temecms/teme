<?php

/**
 * What UpdateType needs: the type (its key naming the row), and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use App\Domain\Types\TypeDefinition;
use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateTypeInput implements OperationInput
{
    public function __construct(
        public TypeDefinition $type,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
