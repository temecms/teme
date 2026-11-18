<?php

/**
 * Sets backups up (I-2401): the first place and its repository, opened by
 * the machine key, and a recovery key shown once, which ConfirmRecoveryKey
 * records when two of its groups are typed back. On a setup not yet finished,
 * it keeps the place, sweeps the key nobody saw, and shows a new one.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\PlaceFailure;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyChallenge;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Domain\Backups\RecoveryKeySource;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class SetUpBackups extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly Restic $restic,
        private readonly RecoveryKeySource $source,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var SetUpBackupsInput $input */
        $this->unfinishedPlaceFor($input);

        if ($input->place !== null) {
            $problems = $input->place->problems();

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }

            $held = $input->place->alreadyHoldsBackups();

            if ($held !== null) {
                throw ValidationException::withMessages(['path' => $held]);
            }
        }

        $label = trim($input->label);

        if ($label === '' || mb_strlen($label) > 100) {
            throw ValidationException::withMessages(['label' => 'Give the key a name of up to 100 characters, such as who holds it.']);
        }
    }

    protected function perform(OperationInput $input): ShownRecoveryKey
    {
        /** @var SetUpBackupsInput $input */
        $this->holdKeys();

        // Another setup may have committed since validate() looked.
        $unfinished = $this->unfinishedPlaceFor($input);
        $label = trim($input->label);
        $now = now();

        if ($unfinished === null) {
            /** @var NewPlace $place */
            $place = $input->place;
            $machine = $this->keys->ensureMachineKey($input->actorId);

            [$machineId, $recoveryId, $key, $repositoryId] = PlaceRepositories::during(function (PlaceRepositories $places) use ($place, $machine): array {
                $repository = $places->repository($place, $machine);
                $made = $this->restic->run($repository, ['init'], timeout: PlaceRepositories::INIT_SECONDS);
                $repositoryId = $made->message('initialized')['id'] ?? null;

                if (! $made->succeeded() || ! is_string($repositoryId) || preg_match('/^[0-9a-f]{64}$/', $repositoryId) !== 1) {
                    throw PlaceFailure::from($made, $place->name(), 'setting up');
                }

                $machineId = $this->keys->keyIds($repository, $place->name())['current'];
                $key = $this->source->make();
                $recoveryId = $this->keys->add($repository, $key->value(), $place->name());

                return [$machineId, $recoveryId, $key, $repositoryId];
            });

            $destination = BackupDestination::query()->create([
                'id' => $place->id,
                'name' => $place->name(),
                'kind' => $place->kind(),
                'location' => $place->location(),
                'credentials' => $place->credentials(),
                'off_server' => $place->kind()->isOffServer(),
                'repository_id' => $repositoryId,
                'state' => BackupDestinationState::Ready,
                'last_answered_at' => $now,
                'added_by' => $this->actor($input),
            ]);

            $this->record($destination, $machineId, BackupKeyRole::Machine, BackupKeys::MACHINE_LABEL, $input, $now, pending: false);
            $this->record($destination, $recoveryId, BackupKeyRole::Recovery, $label, $input, $now, pending: true);

            $this->log->record(SecurityEventType::BackupsSetUp, $input->actorId, context: ['destination' => $destination->id, 'kind' => $destination->kind->value]);
        } else {
            $destination = $unfinished;
            $machine = $this->keys->machineKey();

            [$recoveryId, $key] = PlaceRepositories::during(function (PlaceRepositories $places) use ($destination, $machine): array {
                $repository = $places->repository($destination, $machine);
                $this->keys->removeAbandoned($destination, $repository);
                $key = $this->source->make();

                return [$this->keys->add($repository, $key->value(), $destination->name), $key];
            });

            $this->record($destination, $recoveryId, BackupKeyRole::Recovery, $label, $input, $now, pending: true);
        }

        return new ShownRecoveryKey($key, RecoveryKeyChallenge::for(RecoveryKeyPurpose::SetUp, $label, $key, $this->source->positionsToAsk(), [$destination->id => $recoveryId]));
    }

    /**
     * The place of a setup not yet finished, or null for a first setup; the
     * refusals when the input doesn't fit where setup stands.
     *
     * @throws ValidationException
     */
    private function unfinishedPlaceFor(SetUpBackupsInput $input): ?BackupDestination
    {
        if ($this->setup->isFinished()) {
            throw ValidationException::withMessages(['place' => 'Backups are already set up.']);
        }

        $unfinished = $this->setup->unfinishedPlace();

        if ($unfinished !== null && $input->place !== null) {
            throw ValidationException::withMessages(['place' => "Backups are being set up at {$unfinished->name}. Finish that, or remove {$unfinished->name} to start again."]);
        }

        if ($unfinished === null && $input->place === null) {
            throw ValidationException::withMessages(['place' => 'Choose the first place to keep backups.']);
        }

        return $unfinished;
    }

    private function actor(SetUpBackupsInput $input): ?int
    {
        return $input->actorId === null ? null : (int) $input->actorId;
    }

    /**
     * A pending key's row has removed_at equal to added_at, until ConfirmRecoveryKey clears it.
     */
    private function record(BackupDestination $place, string $resticKeyId, BackupKeyRole $role, string $label, SetUpBackupsInput $input, CarbonInterface $now, bool $pending): void
    {
        BackupKey::query()->create([
            'destination_id' => $place->id,
            'restic_key_id' => $resticKeyId,
            'role' => $role,
            'label' => $label,
            'added_by' => $this->actor($input),
            'added_at' => $now,
            'removed_at' => $pending ? $now : null,
        ]);
    }
}
