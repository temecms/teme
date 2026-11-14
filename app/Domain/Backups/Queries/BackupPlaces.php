<?php

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Models\BackupDestination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The places a backup run may use, whether backups are set up or paused,
 * and the machine key the runs open places with (I-360). Whether backups
 * are set up and the machine key are asked of the classes that own them.
 */
final readonly class BackupPlaces
{
    /** What a backup, a prune or a key change says while a restore's places wait for an answer. */
    public const string PAUSED = 'Backups are paused because this site was restored, until you say whether this server is now the site.';

    public function __construct(private BackupSetup $setup, private BackupKeys $keys) {}

    /**
     * The places a backup may use: ready or failing, never removed, never
     * paused by a restore, oldest first, so a backup tries them in the order
     * they were added (I-360).
     *
     * @return Collection<int, BackupDestination>
     */
    public function inUse(): Collection
    {
        return BackupDestination::query()
            ->whereIn('state', [BackupDestinationState::Ready->value, BackupDestinationState::Failing->value])
            ->whereNull('paused_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The machine key Teme's own runs open the places with, or null when
     * there is none (I-359).
     */
    public function machineKey(): ?string
    {
        try {
            return $this->keys->machineKey();
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Whether backups are set up: a recovery key someone holds is recorded on
     * a place in use (I-2401).
     */
    public function isSetUp(): bool
    {
        return $this->setup->isFinished();
    }

    /**
     * Whether a restore's places wait for a person to say whether this
     * server is now the site: one not removed has a pause.
     */
    public function isPaused(): bool
    {
        return BackupDestination::query()
            ->whereNotNull('paused_at')
            ->where('state', '<>', BackupDestinationState::Removed->value)
            ->exists();
    }
}
