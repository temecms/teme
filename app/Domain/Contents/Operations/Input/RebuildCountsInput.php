<?php

/**
 * What RebuildCounts needs: whether to fix drift or only report it. Only the
 * operator runs it, so there is no actor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RebuildCountsInput implements OperationInput
{
    public function __construct(public bool $fix) {}

    public function actorId(): ?string
    {
        return null;
    }
}
