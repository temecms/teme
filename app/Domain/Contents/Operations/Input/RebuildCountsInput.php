<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RebuildCounts needs: whether to fix drift or only report it. Only the
 * operator runs it, so there is no actor.
 */
final readonly class RebuildCountsInput implements OperationInput
{
    public function __construct(public bool $fix) {}

    public function actorId(): ?string
    {
        return null;
    }
}
