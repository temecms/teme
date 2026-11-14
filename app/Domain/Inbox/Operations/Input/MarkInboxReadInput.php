<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What MarkInboxRead needs: whose inbox, and the one item to mark, or null
 * for all of them.
 */
final readonly class MarkInboxReadInput implements OperationInput
{
    public function __construct(
        public ?string $actorId,
        public ?string $itemId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
