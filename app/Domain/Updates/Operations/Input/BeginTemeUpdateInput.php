<?php

/**
 * What BeginTemeUpdate needs: the request to begin. Only the operator, the updater, runs it, so the actor is none.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class BeginTemeUpdateInput implements OperationInput
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
