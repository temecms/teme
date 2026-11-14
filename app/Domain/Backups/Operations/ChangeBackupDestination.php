<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\ChangeBackupDestinationInput;
use App\Domain\Backups\PlaceFailure;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Changes a place's name, or the address and credentials it is reached at,
 * never pointing it at other backups (I-360).
 */
final class ChangeBackupDestination extends BaseOperation implements RequiresElevation
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
        /** @var ChangeBackupDestinationInput $input */
        $this->ensureSetUp();
        $this->ensureNotPaused();
        $place = $this->placeInUse($input->placeId);
        $changed = $this->changedPlace($place, $input);
        $problems = $changed->problems();

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $name = $changed->name();
        $taken = $this->setup->placesInUse()->contains(fn (BackupDestination $each): bool => $each->id !== $place->id && mb_strtolower($each->name) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => "Another place is already called {$name}."]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var ChangeBackupDestinationInput $input */
        $place = $this->placeInUse($input->placeId);
        $changed = $this->changedPlace($place, $input);

        $fields = array_values(array_filter([
            $changed->name() !== $place->name ? 'name' : null,
            self::withoutFingerprint($changed->location()) != self::withoutFingerprint($place->location) ? 'location' : null,
            $changed->credentials() != $place->credentials ? 'credentials' : null,
        ]));

        if ($fields === []) {
            return $place->id;
        }

        $update = ['name' => $changed->name()];

        if ($fields !== ['name']) {
            $machine = $this->keys->machineKey();

            $opened = PlaceRepositories::during(fn (PlaceRepositories $places) => $this->restic->run(
                $places->repository($changed, $machine),
                ['cat', 'config'],
                timeout: PlaceRepositories::ANSWER_SECONDS,
            ));

            if (! $opened->succeeded()) {
                throw PlaceFailure::from($opened, $place->name, 'opening');
            }

            if (($opened->json()['id'] ?? null) !== $place->repository_id) {
                throw ValidationException::withMessages(['place' => "That address holds other backups, not {$place->name}’s."]);
            }

            $update += [
                'location' => $changed->location(),
                'credentials' => $changed->credentials(),
                'state' => BackupDestinationState::Ready,
                'last_error' => null,
                'last_answered_at' => now(),
            ];
        }

        $place->forceFill($update)->save();

        $this->log->record(SecurityEventType::BackupDestinationChanged, $input->actorId, context: ['destination' => $place->id, 'fields' => $fields]);

        return $place->id;
    }

    /**
     * The place as the input would leave it. An SFTP place whose server is
     * the same keeps its confirmed host key; a new server needs one confirmed.
     *
     * @throws ValidationException
     */
    private function changedPlace(BackupDestination $place, ChangeBackupDestinationInput $input): NewPlace
    {
        $location = $input->location;

        if ($place->kind === BackupDestinationKind::Sftp && ! isset($location['host_key'])) {
            $sameServer = ($location['host'] ?? null) === ($place->location['host'] ?? null)
                && (int) ($location['port'] ?? 22) === (int) ($place->location['port'] ?? 22);

            if (! $sameServer) {
                throw ValidationException::withMessages(['host_key' => 'Confirm the server’s fingerprint first.']);
            }

            $location['host_key'] = (string) ($place->location['host_key'] ?? '');
        }

        return NewPlace::of($place->kind, $input->name, $location, $input->credentials() ?? $place->credentials, $place->id);
    }

    /**
     * @param  array<string, string|int>  $location
     *
     * @return array<string, string|int>
     */
    private static function withoutFingerprint(array $location): array
    {
        unset($location['fingerprint']);

        return $location;
    }
}
