<?php

/**
 * What MarkVisibilityWaiting needs: the source, and who is importing (null
 * for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class MarkVisibilityWaitingInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
