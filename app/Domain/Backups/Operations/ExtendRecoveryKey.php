<?php

/**
 * Adds a recovery key someone holds to the places in use it doesn't open yet,
 * as adding a place does with the key typed once (I-2402).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\ExtendRecoveryKeyInput;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class ExtendRecoveryKey extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        $this->ensureSetUp();
        $this->ensureNotPaused();
    }

    /**
     * @return array{label: string, places: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ExtendRecoveryKeyInput $input */
        $this->holdKeys();
        $this->ensureSetUp();
        $this->ensureNotPaused();

        $machine = $this->keys->machineKey();
        $typed = $input->recoveryKey->value();
        $actor = $input->actorId === null ? null : (int) $input->actorId;
        $now = now();

        [$label, $added] = PlaceRepositories::during(function (PlaceRepositories $open) use ($machine, $typed, $actor, $now): array {
            $label = null;
            $missing = [];

            foreach ($this->setup->placesInUse() as $place) {
                $resticKeyId = $this->keys->opensWith($open->repository($place, $typed), $place->name);

                if ($resticKeyId === null) {
                    $missing[] = $place;

                    continue;
                }

                $recorded = $place->keys()->where('role', BackupKeyRole::Recovery->value)->whereNull('removed_at')->where('restic_key_id', $resticKeyId)->first();

                if ($recorded === null) {
                    throw ValidationException::withMessages(['recovery_key' => "That key opens the backups at {$place->name}, but it isn’t one of this site’s recovery keys."]);
                }

                $label ??= $recorded->label;
            }

            if ($label === null) {
                throw ValidationException::withMessages(['recovery_key' => 'That recovery key doesn’t open any place backups are kept.']);
            }

            if ($missing === []) {
                throw ValidationException::withMessages(['recovery_key' => "{$label} already opens every place backups are kept."]);
            }

            $added = [];

            foreach ($missing as $place) {
                $added[$place->name] = $this->keys->add($open->repository($place, $machine), $typed, $place->name);
                BackupKey::query()->create([
                    'destination_id' => $place->id,
                    'restic_key_id' => $added[$place->name],
                    'role' => BackupKeyRole::Recovery,
                    'label' => $label,
                    'added_by' => $actor,
                    'added_at' => $now,
                ]);
            }

            return [$label, array_keys($added)];
        });

        $this->log->record(SecurityEventType::BackupRecoveryKeyAdded, $input->actorId, context: ['label' => $label, 'places' => count($added)]);

        return ['label' => $label, 'places' => $added];
    }
}
