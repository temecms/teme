<?php

/**
 * What CancelTemeUpdate needs: the request's ID and who cancels it, or null for the operator.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CancelTemeUpdateInput implements OperationInput
{
    public function __construct(
        public string $updateId,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
