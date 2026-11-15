<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A publication to schedule: which revision of which article, whether it is
 * a first publication or an update to a live one, when, and who asks. The
 * time is ISO 8601 with its offset.
 */
final readonly class SchedulePublicationInput implements OperationInput
{
    /**
     * @param  string|null  $via  the plugin code that asked to schedule, or null for a person
     */
    public function __construct(
        public string $contentId,
        public string $revisionId,
        public string $action,
        public string $runAt,
        public ?string $actorId,
        public ?string $via = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
