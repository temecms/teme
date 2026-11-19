<?php

/**
 * What BeginRestore needs: the backup's run, its place, the backup's moment, and who restores.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

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
