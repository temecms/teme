<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * How many due publications one run of the scheduler claims. The operator
 * acts, so there is no actor.
 */
final readonly class ClaimDuePublicationsInput implements OperationInput
{
    public function __construct(
        public int $limit,
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
