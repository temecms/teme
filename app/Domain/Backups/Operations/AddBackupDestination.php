<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\PlaceAdded;
use App\Domain\Backups\PlaceFailure;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Adds a place to keep backups (I-2402): its repository made with the
 * chunker of a place already in use, locked with the machine key and with the
 * recovery key a person typed once, which Teme then forgets.
 */
final class AddBackupDestination extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly Restic $restic,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var AddBackupDestinationInput $input */
        $this->ensureSetUp();
        $this->ensureNotPaused();

        $problems = $input->place->problems();

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $held = $input->place->alreadyHoldsBackups();

        if ($held !== null) {
            throw ValidationException::withMessages(['path' => $held]);
        }

        $this->ensureNameFree($input);
    }

    protected function perform(OperationInput $input): PlaceAdded
    {
        /** @var AddBackupDestinationInput $input */
        $this->holdKeys();

        // Another Operation may have changed the places since validate() looked.
        $this->ensureSetUp();
        $this->ensureNotPaused();
        $this->ensureNameFree($input);

        $place = $input->place;
        $name = $place->name();
        $machine = $this->keys->machineKey();
        $now = now();

        [$machineId, $recoveryId, $label, $repositoryId] = PlaceRepositories::during(function (PlaceRepositories $places) use ($input, $place, $name, $machine): array {
            $new = $places->repository($place, $machine);
            $source = $this->sourceFor($places, $new, $name, $machine);

            // The typed key must open the place it is checked against, and be one of the site's own.
            $typedId = $this->keys->opensWith($places->repository($source, $input->recoveryKey->value()), $source->name);

            if ($typedId === null) {
                throw ValidationException::withMessages(['recovery_key' => "That recovery key doesn’t open the backups at {$source->name}."]);
            }

            $recorded = $source->keys()->where('role', BackupKeyRole::Recovery->value)->whereNull('removed_at')->where('restic_key_id', $typedId)->first();

            if ($recorded === null) {
                throw ValidationException::withMessages(['recovery_key' => "That key opens the backups at {$source->name}, but it isn’t one of this site’s recovery keys."]);
            }

            $made = $this->restic->run($new, ['init', '--copy-chunker-params'], from: $places->repository($source, $machine), timeout: PlaceRepositories::INIT_SECONDS);
            $repositoryId = $made->message('initialized')['id'] ?? null;

            if (! $made->succeeded() || ! is_string($repositoryId) || preg_match('/^[0-9a-f]{64}$/', $repositoryId) !== 1) {
                throw PlaceFailure::from($made, $name, 'adding a place');
            }

            $machineId = $this->keys->keyIds($new, $name)['current'];
            $recoveryId = $this->keys->add($new, $input->recoveryKey->value(), $name);

            return [$machineId, $recoveryId, $recorded->label, $repositoryId];
        });

        $actor = $input->actorId === null ? null : (int) $input->actorId;

        $row = BackupDestination::query()->create([
            'id' => $place->id,
            'name' => $name,
            'kind' => $place->kind(),
            'location' => $place->location(),
            'credentials' => $place->credentials(),
            'off_server' => $place->kind()->isOffServer(),
            'repository_id' => $repositoryId,
            'state' => BackupDestinationState::Ready,
            'last_answered_at' => $now,
            'added_by' => $actor,
        ]);

        foreach ([[$machineId, BackupKeyRole::Machine, BackupKeys::MACHINE_LABEL], [$recoveryId, BackupKeyRole::Recovery, $label]] as [$resticKeyId, $role, $keyLabel]) {
            BackupKey::query()->create([
                'destination_id' => $row->id,
                'restic_key_id' => $resticKeyId,
                'role' => $role,
                'label' => $keyLabel,
                'added_by' => $actor,
                'added_at' => $now,
            ]);
        }

        $this->log->record(SecurityEventType::BackupDestinationAdded, $input->actorId, context: ['destination' => $row->id, 'kind' => $row->kind->value]);

        return new PlaceAdded($row->id, $this->setup->labelsMissingAt($row));
    }

    /**
     * The place in use the new repository copies its chunker from: a folder,
     * else one restic can open beside it (I-2450).
     *
     * @throws ValidationException
     */
    private function sourceFor(PlaceRepositories $places, ResticRepository $new, string $name, string $machine): BackupDestination
    {
        $inUse = $this->setup->placesInUse();
        $folder = $inUse->first(fn (BackupDestination $each): bool => ! $each->kind->isOffServer());

        if ($folder !== null) {
            return $folder;
        }

        foreach ($inUse as $each) {
            if (PlaceRepositories::canOpenTogether($new, $places->repository($each, $machine))) {
                return $each;
            }
        }

        throw ValidationException::withMessages(['place' => "restic can’t open {$name} beside the places already in use, because each needs its own S3 keys. Add a folder on this server first, then {$name}."]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureNameFree(AddBackupDestinationInput $input): void
    {
        $name = $input->place->name();
        $taken = $this->setup->placesInUse()->contains(fn (BackupDestination $each): bool => mb_strtolower($each->name) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => "Another place is already called {$name}."]);
        }
    }
}
