<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * One scheduled publication to run, and the person it runs as: the row's
 * scheduler, named explicitly (I-392), or null when that account is gone.
 */
final readonly class ScheduledPublicationInput implements OperationInput
{
    public function __construct(
        public string $scheduledPublicationId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
