<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use App\Domain\Updates\ReleaseCheck;
use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordReleaseCheck needs: the answer, when it came, and whether a token was sent. Only the operator runs it, so the actor is none.
 */
final readonly class RecordReleaseCheckInput implements OperationInput
{
    public function __construct(
        public ReleaseCheck $check,
        public CarbonImmutable $at,
        public bool $tokenSet,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
