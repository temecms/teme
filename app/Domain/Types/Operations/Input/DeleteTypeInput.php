<?php

/**
 * What DeleteType needs: the type's key, and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteTypeInput implements OperationInput
{
    public function __construct(
        public string $key,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
