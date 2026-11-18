<?php

/**
 * A reviewer's decision on one pending review, with the note a return
 * requires.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
