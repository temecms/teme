<?php

/**
 * What MarkInboxRead needs: whose inbox, and the one item to mark, or null
 * for all of them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Inbox\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
