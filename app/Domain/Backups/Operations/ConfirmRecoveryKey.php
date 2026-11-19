<?php

/**
 * Puts a shown recovery key in use once two of its groups are typed back
 * (I-2401): its pending rows at every place become in use, and for a
 * replacement the old key's rows are marked removed, and the old key leaves
 * the repositories after the commit. Until then the key is pending, and the
 * next attempt sweeps it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyConfirmed;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use Throwable;

final class ConfirmRecoveryKey extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    private const string STALE = 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.';

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var ConfirmRecoveryKeyInput $input */
        $challenge = $input->challenge;

        if (! $challenge->matches($input->groups())) {
            [$a, $b] = $challenge->positions;

            throw ValidationException::withMessages(['groups' => "Those aren’t groups {$a} and {$b} of the recovery key. Check the key you wrote down, and type them again."]);
        }

        $this->checkPending($input);
        $this->checkLabel($input);
    }

    protected function perform(OperationInput $input): RecoveryKeyConfirmed
    {
        /** @var ConfirmRecoveryKeyInput $input */
        $this->holdKeys();

        // Another Operation may have changed the keys since validate() looked.
        $this->checkPending($input);
        $this->checkLabel($input);

        $challenge = $input->challenge;
        $machine = $this->keys->machineKey();
        $places = $this->setup->placesInUse();
        $result = new RecoveryKeyConfirmed($challenge->label, $places->count());

        PlaceRepositories::during(function (PlaceRepositories $open) use ($places, $challenge, $machine): void {
            foreach ($places as $place) {
                $ids = $this->keys->keyIds($open->repository($place, $machine), $place->name)['ids'];

                if (! in_array($challenge->pending[$place->id], $ids, true)) {
                    throw ValidationException::withMessages(['groups' => self::STALE]);
                }
            }
        });

        $actor = $input->actorId === null ? null : (int) $input->actorId;
        $pendingIds = array_values($challenge->pending);
        $old = [];

        if ($challenge->purpose === RecoveryKeyPurpose::Replace) {
            // Before the new key's rows are cleared, so they are not mistaken for the old key's.
            $old = BackupKey::query()
                ->where('role', BackupKeyRole::Recovery->value)
                ->where('label', $challenge->label)
                ->whereNull('removed_at')
                ->whereNotIn('restic_key_id', $pendingIds)
                ->get()
                ->all();

            foreach ($old as $row) {
                $row->forceFill(['removed_at' => now(), 'removed_by' => $actor])->save();
            }
        }

        foreach ($challenge->pending as $placeId => $resticKeyId) {
            BackupKey::query()
                ->where('destination_id', $placeId)
                ->where('restic_key_id', $resticKeyId)
                ->update(['removed_at' => null, 'added_by' => $actor]);
        }

        if ($old !== []) {
            // After the commit, so nothing that goes wrong there can leave the places without a recovery key.
            DB::afterCommit(function () use ($old, $result, $machine): void {
                $this->removeOldKeys($old, $result, $machine);
            });
        }

        $this->log->record(
            $challenge->purpose === RecoveryKeyPurpose::Replace ? SecurityEventType::BackupRecoveryKeyReplaced : SecurityEventType::BackupRecoveryKeyAdded,
            $input->actorId,
            context: ['label' => $challenge->label, 'places' => $places->count()],
        );

        return $result;
    }

    /**
     * Refuses unless the key is still pending at exactly the places in use.
     *
     * @throws ValidationException
     */
    private function checkPending(ConfirmRecoveryKeyInput $input): void
    {
        $challenge = $input->challenge;
        $inUse = $this->setup->placesInUse()->pluck('id')->all();
        $pending = array_keys($challenge->pending);
        sort($inUse);
        sort($pending);

        $stale = $inUse !== $pending
            || ($challenge->purpose === RecoveryKeyPurpose::SetUp && $this->setup->isFinished());

        foreach ($challenge->pending as $placeId => $resticKeyId) {
            $row = BackupKey::query()->where('destination_id', $placeId)->where('restic_key_id', $resticKeyId)->first();

            $stale = $stale || $row === null || $row->removed_at === null || ! $row->removed_at->equalTo($row->added_at) || $row->removed_by !== null;
        }

        if ($stale) {
            throw ValidationException::withMessages(['groups' => self::STALE]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function checkLabel(ConfirmRecoveryKeyInput $input): void
    {
        $challenge = $input->challenge;

        if ($challenge->purpose === RecoveryKeyPurpose::SetUp) {
            return;
        }

        $this->ensureSetUp();
        $inUse = array_map('mb_strtolower', $this->setup->recoveryKeyLabels());
        $known = in_array(mb_strtolower($challenge->label), $inUse, true);

        if ($challenge->purpose === RecoveryKeyPurpose::Add && $known) {
            throw ValidationException::withMessages(['label' => "Another recovery key is already called {$challenge->label}."]);
        }

        if ($challenge->purpose === RecoveryKeyPurpose::Replace && ! $known) {
            throw ValidationException::withMessages(['label' => "There’s no recovery key called {$challenge->label}."]);
        }
    }

    /**
     * Removes each replaced key from its place; a place that doesn't answer
     * keeps the old key until the next sweep (I-359).
     *
     * @param  list<BackupKey>  $old
     */
    private function removeOldKeys(array $old, RecoveryKeyConfirmed $result, string $machine): void
    {
        PlaceRepositories::during(function (PlaceRepositories $open) use ($old, $result, $machine): void {
            foreach ($old as $row) {
                $place = BackupDestination::query()->find($row->destination_id);

                if ($place === null) {
                    continue;
                }

                try {
                    $this->keys->remove($open->repository($place, $machine), $row->restic_key_id, $place->name);
                } catch (Throwable) {
                    $result->oldKeyKeptAt[] = $place->name;
                }
            }
        });
    }
}
