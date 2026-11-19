<?php

/**
 * Stops Teme using a place (I-360): its backups stay where they are, and it
 * keeps no credentials.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\RemoveBackupDestinationInput;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class RemoveBackupDestination extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var RemoveBackupDestinationInput $input */
        $place = $this->placeInUse($input->placeId);

        // While setup is unfinished the only place can go: that is how a person starts again.
        if ($this->setup->isFinished() && $this->setup->placesInUse()->count() === 1) {
            throw ValidationException::withMessages(['place' => "{$place->name} is the only place backups are kept. Add another place before removing it."]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RemoveBackupDestinationInput $input */
        $place = $this->placeInUse($input->placeId);

        $place->forceFill([
            'state' => BackupDestinationState::Removed,
            'removed_at' => now(),
            'removed_by' => $input->actorId === null ? null : (int) $input->actorId,
            'credentials' => null,
            'last_error' => null,
        ])->save();

        $this->log->record(SecurityEventType::BackupDestinationRemoved, $input->actorId, context: ['destination' => $place->id, 'kind' => $place->kind->value]);

        return $place->id;
    }
}
