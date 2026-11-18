<?php

/**
 * What RebuildImportedCounts needs: who is asking, the import's actor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RebuildImportedCountsInput implements OperationInput
{
    public function __construct(public ?string $actorId) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
