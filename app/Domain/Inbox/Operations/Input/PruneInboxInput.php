<?php

/**
 * What PruneInbox needs: the moment before which items go, how many to
 * delete in this chunk, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Inbox\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

final readonly class PruneInboxInput implements OperationInput
{
    public function __construct(
        public CarbonImmutable $createdBefore,
        public int $limit = 1000,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
