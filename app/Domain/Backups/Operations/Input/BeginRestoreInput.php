<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

/**
 * What BeginRestore needs: the backup's run, its place, the backup's moment, and who restores.
 */
final readonly class BeginRestoreInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public string $place,
        public CarbonImmutable $madeAt,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
