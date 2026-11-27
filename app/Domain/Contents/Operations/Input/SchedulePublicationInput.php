<?php

/**
 * A publication to schedule: which revision of which article, whether it is
 * a first publication or an update to a live one, when, and who asks. The
 * time is ISO 8601 with its offset.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SchedulePublicationInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $revisionId,
        public string $action,
        public string $runAt,
        public ?string $actorId,
        // The plugin that asks, passed on to the before-save check; null for a person.
        public ?string $via = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
