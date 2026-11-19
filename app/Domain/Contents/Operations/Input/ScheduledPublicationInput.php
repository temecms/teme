<?php

/**
 * One scheduled publication to run, and the person it runs as: the row's
 * scheduler, named explicitly (I-392), or null when that account is gone.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
