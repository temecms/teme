<?php

/**
 * Opens a place with the machine key and says, in words, whether it answers,
 * opens and has room (I-360); the place's state follows.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\TestBackupDestinationInput;
use App\Domain\Backups\PlaceAnswer;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Number;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class TestBackupDestination extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupKeys $keys,
        private readonly Restic $restic,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var TestBackupDestinationInput $input */
        $this->placeInUse($input->placeId);
    }

    protected function perform(OperationInput $input): PlaceAnswer
    {
        /** @var TestBackupDestinationInput $input */
        $place = $this->placeInUse($input->placeId);
        $machine = $this->keys->machineKey();

        $result = PlaceRepositories::during(fn (PlaceRepositories $places) => $this->restic->run(
            $places->repository($place, $machine),
            ['cat', 'config'],
            timeout: PlaceRepositories::ANSWER_SECONDS,
        ));

        $answered = $result->succeeded() || in_array($result->outcome, [ResticOutcome::WrongKey, ResticOutcome::Locked], true);
        $freeBytes = null;

        if ($place->kind === BackupDestinationKind::Folder) {
            $free = @disk_free_space((string) ($place->location['path'] ?? ''));
            $freeBytes = $free === false ? null : (int) $free;
        }

        $opened = false;

        if ($result->succeeded() && ($result->json()['id'] ?? null) === $place->repository_id) {
            $opened = true;
            $sentence = "{$place->name} answered, and Teme’s key opened its backups.".($freeBytes === null
                ? ' Teme can’t tell how much room is left there.'
                : ' '.Number::fileSize($freeBytes, 1).' free on its disk.');
        } elseif ($result->succeeded()) {
            $sentence = "{$place->name} holds other backups than the ones Teme made there.";
        } else {
            $sentence = $result->sentence($place->name);
        }

        $place->forceFill([
            'state' => $opened ? BackupDestinationState::Ready : BackupDestinationState::Failing,
            'last_error' => $opened ? null : $sentence,
            'last_answered_at' => $answered ? now() : $place->last_answered_at,
        ])->save();

        return new PlaceAnswer($answered, $opened, $freeBytes, $sentence);
    }
}
