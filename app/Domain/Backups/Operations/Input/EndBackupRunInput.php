<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\PlaceOutcome;
use Teme\Contracts\Operations\OperationInput;

/**
 * What EndBackupRun needs: the run, whether it succeeded, its message, figures
 * and snapshot, the place that took it, what it saw at each place it reached
 * for, and who is asking.
 */
final readonly class EndBackupRunInput implements OperationInput
{
    /**
     * @param  array<string, int|string|bool|null>  $figures
     * @param  array<string, PlaceOutcome>  $places  place ID => what the run saw there
     */
    public function __construct(
        public string $runId,
        public bool $succeeded,
        public ?string $message = null,
        public array $figures = [],
        public ?string $snapshotId = null,
        public ?string $destinationId = null,
        public array $places = [],
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
