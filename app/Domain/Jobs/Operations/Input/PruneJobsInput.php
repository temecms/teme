<?php

/**
 * What PruneJobs needs: the moment before which ended jobs go, how many to delete in this chunk, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

final readonly class PruneJobsInput implements OperationInput
{
    public function __construct(
        public CarbonImmutable $endedBefore,
        public int $limit = 1000,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
