<?php

/**
 * What PruneAiActivity needs: the moment before which a row is due, how many
 * rows one chunk deletes, and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Operations\Input;

use Carbon\CarbonInterface;
use Teme\Contracts\Operations\OperationInput;

final readonly class PruneAiActivityInput implements OperationInput
{
    public function __construct(
        public CarbonInterface $createdBefore,
        public int $limit,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
