<?php

/**
 * What MarkAuthorsWaiting needs: the source, the run whose people phase may
 * have made a person or first matched one, and who is importing (null for
 * the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class MarkAuthorsWaitingInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $runId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
