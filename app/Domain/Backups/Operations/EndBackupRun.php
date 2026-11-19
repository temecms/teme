<?php

/**
 * Records how a run ended (I-362): finished or failed, when, its figures,
 * restic's snapshot, the place that took it and its message; and each place
 * it reached for turned ready or failing by what it saw there.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Concerns\AuthorizesBackupRuns;
use App\Domain\Backups\Operations\Input\EndBackupRunInput;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class EndBackupRun extends BaseOperation
{
    use AuthorizesBackupRuns;

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var EndBackupRunInput $input */
        $now = CarbonImmutable::now();
        $run = BackupRun::query()->lockForUpdate()->find($input->runId);

        if ($run === null || $run->state !== BackupRunState::Running) {
            throw ValidationException::withMessages(['run' => ['This run has already ended.']]);
        }

        $run->update([
            'state' => $input->succeeded ? BackupRunState::Finished : BackupRunState::Failed,
            'ended_at' => $now->max($run->started_at),
            'figures' => $input->figures,
            'snapshot_id' => $input->snapshotId,
            'destination_id' => $input->destinationId ?? $run->destination_id,
            'message' => $input->message === null ? null : mb_strimwidth($input->message, 0, 2000, '…'),
        ]);

        foreach ($input->places as $placeId => $outcome) {
            $place = BackupDestination::query()->lockForUpdate()->find($placeId);

            // A place removed meanwhile keeps nothing but its removal (I-2718).
            if ($place === null || $place->state === BackupDestinationState::Removed) {
                continue;
            }

            $place->update($outcome->error === null
                ? ['state' => BackupDestinationState::Ready, 'last_error' => null, 'last_answered_at' => $now]
                : [
                    'state' => BackupDestinationState::Failing,
                    'last_error' => mb_strimwidth($outcome->error, 0, 1000, '…'),
                    'last_answered_at' => $outcome->reached ? $now : $place->last_answered_at,
                ]);
        }

        return null;
    }
}
