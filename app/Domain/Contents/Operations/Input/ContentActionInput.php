<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What an Operation acting on one whole item needs — unpublish, trash,
 * restore or delete for good: which item, and who is asking. A null actor is
 * the operator, and only DeleteContentPermanently accepts one.
 */
final readonly class ContentActionInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
