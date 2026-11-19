<?php

/**
 * Who may change backups: a person who holds backups.manage, after their
 * password, or the operator at the server (docs/architecture/operations.md,
 * The operator). Every backup Operation uses it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Concerns;

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyChallenge;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Domain\Backups\RecoveryKeySource;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

trait ManagesBackups
{
    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        $actorId = $input->actorId();
        $allowed = $actorId === null
            ? Operator::isPresent()
            : User::query()->find($actorId)?->can('backups.manage') === true;

        if (! $allowed) {
            throw new AuthorizationException('Only an administrator manages backups.');
        }
    }

    /**
     * Refuses until a recovery key is recorded (I-2401).
     */
    protected function ensureSetUp(): void
    {
        if (! app(BackupSetup::class)->isFinished()) {
            throw ValidationException::withMessages(['place' => 'Finish setting backups up first.']);
        }
    }

    /**
     * Refuses while a restore's places wait for an answer: a key or a place
     * added or changed then would be written into repositories another
     * install may be using. Reading (checking a key, confirming one already
     * shown, testing a place) stays allowed (docs/architecture/backups.md).
     */
    protected function ensureNotPaused(): void
    {
        if (app(BackupPlaces::class)->isPaused()) {
            throw ValidationException::withMessages(['place' => BackupPlaces::PAUSED]);
        }
    }

    /**
     * Waits for any other Operation changing backup keys to commit, and
     * holds them off until this one's transaction ends. Called first in
     * perform(), which then re-reads what validate() decided (I-359).
     */
    protected function holdKeys(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('teme.backup-keys'))");
    }

    /**
     * The place in use with this ID, else the refusal every Operation on one gives.
     *
     * @throws ValidationException
     */
    protected function placeInUse(string $placeId): BackupDestination
    {
        return app(BackupSetup::class)->placesInUse()->firstWhere('id', $placeId)
            ?? throw ValidationException::withMessages(['place' => 'There’s no such place.']);
    }

    /**
     * Adds a new recovery key to every place in use, after sweeping the keys
     * Teme gave up on, and records each as pending until it is typed back
     * (I-359). Called inside perform(), under holdKeys().
     */
    protected function showNewKey(BackupKeys $keys, RecoveryKeySource $source, string $label, RecoveryKeyPurpose $purpose, ?string $actorId): ShownRecoveryKey
    {
        $machine = $keys->machineKey();
        $places = app(BackupSetup::class)->placesInUse();
        $now = now();

        $key = $source->make();

        $pending = PlaceRepositories::during(function (PlaceRepositories $open) use ($keys, $places, $machine, $key): array {
            $repositories = [];

            foreach ($places as $place) {
                $repositories[$place->id] = $open->repository($place, $machine);
                $keys->removeAbandoned($place, $repositories[$place->id]);
            }

            $added = [];

            foreach ($places as $place) {
                $added[$place->id] = $keys->add($repositories[$place->id], $key->value(), $place->name);
            }

            return $added;
        });

        foreach ($pending as $placeId => $resticKeyId) {
            BackupKey::query()->create([
                'destination_id' => $placeId,
                'restic_key_id' => $resticKeyId,
                'role' => BackupKeyRole::Recovery,
                'label' => $label,
                'added_by' => $actorId === null ? null : (int) $actorId,
                'added_at' => $now,
                'removed_at' => $now,
            ]);
        }

        return new ShownRecoveryKey($key, RecoveryKeyChallenge::for($purpose, $label, $key, $source->positionsToAsk(), $pending));
    }
}
