<?php

/**
 * What CreateType needs: the type, and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use App\Domain\Types\TypeDefinition;
use Teme\Contracts\Operations\OperationInput;

final readonly class CreateTypeInput implements OperationInput
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
