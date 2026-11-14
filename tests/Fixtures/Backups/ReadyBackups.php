<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\ConfirmRecoveryKey;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RecoveryKeyConfirmed;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Closure;

/**
 * A site whose backups are set up and confirmed, by the operator, at a fresh
 * folder called This server, with the real restic (I-2400); and the steps a
 * test takes to get there.
 */
final class ReadyBackups
{
    private function __construct(
        public readonly BackupDestination $place,
        public readonly RecoveryKey $recoveryKey,
        public readonly string $folder,
    ) {}

    /**
     * Backups set up and confirmed by the operator at a fresh folder called
     * This server.
     */
    public static function atFolder(): self
    {
        $place = self::folderPlace('This server');
        $shown = self::setUp($place);
        self::confirm($shown);

        return new self(BackupDestination::query()->findOrFail($place->id), $shown->key, (string) $place->location()['path']);
    }

    /**
     * A new folder place at a fresh folder under the system's temporary folder.
     */
    public static function folderPlace(string $name = 'Second place'): NewPlace
    {
        return NewPlace::folder($name, TemporaryRepository::freshFolder());
    }

    /**
     * Sets backups up at $place and returns the key shown. A null actor is the
     * operator; an actor is that person after the password.
     */
    public static function setUp(NewPlace $place, ?string $actorId = null): ShownRecoveryKey
    {
        config(['teme.backups.cache_path' => '']);

        return self::elevated(fn (): ShownRecoveryKey => app(SetUpBackups::class)->execute(new SetUpBackupsInput($place, actorId: $actorId)));
    }

    /**
     * Types back the two groups asked of $shown, and records the key.
     */
    public static function confirm(ShownRecoveryKey $shown, ?string $actorId = null): RecoveryKeyConfirmed
    {
        [$a, $b] = $shown->challenge->positions;
        $groups = [$a => $shown->key->group($a), $b => $shown->key->group($b)];

        return self::elevated(fn (): RecoveryKeyConfirmed => app(ConfirmRecoveryKey::class)->execute(new ConfirmRecoveryKeyInput($shown->challenge, $groups, $actorId)));
    }

    public function delete(): void
    {
        TemporaryRepository::remove($this->folder);
    }

    /**
     * Runs $run with the operator's elevation bound, then puts back whatever
     * answered for elevation before.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     *
     * @return T
     */
    private static function elevated(Closure $run): mixed
    {
        $held = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            return $run();
        } finally {
            app()->instance(Elevation::class, $held);
        }
    }
}
