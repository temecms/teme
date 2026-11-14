<?php

declare(strict_types=1);

namespace App\Domain\Ai\Operations\Input;

use App\Domain\Ai\AiOutcome;
use Teme\Contracts\Operations\OperationInput;

/**
 * What SettleAiActivity needs: the suggestion's row, how it is settled, the
 * revision it made when used, and the person settling it.
 */
final readonly class SettleAiActivityInput implements OperationInput
{
    public function __construct(
        public string $activityId,
        public AiOutcome $outcome,
        public ?string $revisionId = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
