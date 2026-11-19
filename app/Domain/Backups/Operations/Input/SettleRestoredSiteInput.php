<?php

/**
 * What SettleRestoredSite needs: the run that made the backup and its manifest's snapshot, the backup's moment, when the restore began, the place it came from and its repository, the safety backup, and who restores.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

final readonly class SettleRestoredSiteInput implements OperationInput
{
    public function __construct(
        public string $sourceRunId,
        public ?string $manifestSnapshotId,
        public CarbonImmutable $madeAt,
        public CarbonImmutable $startedAt,
        public string $place,
        public ?string $placeRepositoryId,
        public ?string $safetyRunId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
