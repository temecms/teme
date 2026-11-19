<?php

/**
 * What UpdateField needs: the type, the key of the field it changes, the field as the sheet gave it, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Operations\Input;

use App\Domain\Fields\Stored\FieldDefinition;
use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateFieldInput implements OperationInput
{
    public function __construct(
        public string $type,
        public string $key,
        public FieldDefinition $definition,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
