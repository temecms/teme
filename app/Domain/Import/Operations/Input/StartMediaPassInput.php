<?php

/**
 * What `StartMediaPass` needs: the finished import whose pictures it brings, and who starts it (null for the operator).
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class StartMediaPassInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
