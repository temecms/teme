<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A reviewer's decision on one pending review, with the note a return
 * requires.
 */
final readonly class ReviewDecisionInput implements OperationInput
{
    public function __construct(
        public string $reviewId,
        public ?string $actorId,
        public ?string $note = null,
        /** For Approve and schedule (I-437): when to publish, ISO 8601 in UTC. */
        public ?string $runAt = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
