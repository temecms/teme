<?php

/**
 * Why a scheduled publication was held or failed. The operator records it,
 * so there is no actor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ScheduledPublicationOutcomeInput implements OperationInput
{
    public function __construct(
        public string $scheduledPublicationId,
        public string $reason,
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
