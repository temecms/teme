<?php

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whether backups are set up, and the places and recovery keys in use
 * (I-2401): what Tools › Backups, the Status check and the backup run read.
 */
final readonly class BackupSetup
{
    /**
     * The places in use (ready or failing), oldest first.
     *
     * @return Collection<int, BackupDestination>
     */
    public function placesInUse(): Collection
    {
        return BackupDestination::query()
            ->where('state', '<>', BackupDestinationState::Removed->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether a recovery key is recorded on a place in use.
     */
    public function isFinished(): bool
    {
        return $this->recoveryKeysInUse()->exists();
    }

    /**
     * The place of a setup not yet finished: the oldest place in use, or null
     * when setup is finished or no place exists.
     */
    public function unfinishedPlace(): ?BackupDestination
    {
        return $this->isFinished() ? null : $this->placesInUse()->first();
    }

    /**
     * The labels of the recovery keys in use, sorted.
     *
     * @return list<string>
     */
    public function recoveryKeyLabels(): array
    {
        return $this->sorted($this->recoveryKeysInUse()->distinct()->pluck('label')->all());
    }

    /**
     * The labels of the recovery keys in use that don't open $place.
     *
     * @return list<string>
     */
    public function labelsMissingAt(BackupDestination $place): array
    {
        $there = $this->recoveryKeysInUse()->where('destination_id', $place->id)->pluck('label')->all();

        return $this->sorted(array_diff($this->recoveryKeyLabels(), $there));
    }

    /**
     * The folder offered first when backups are set up (I-2401).
     */
    public static function suggestedFolder(): string
    {
        return storage_path('app/backups');
    }

    /**
     * @return Builder<BackupKey>
     */
    private function recoveryKeysInUse(): Builder
    {
        return BackupKey::query()
            ->where('role', BackupKeyRole::Recovery->value)
            ->whereNull('removed_at')
            ->whereIn('destination_id', BackupDestination::query()->where('state', '<>', BackupDestinationState::Removed->value)->select('id'));
    }

    /**
     * @param  array<int, string>  $labels
     *
     * @return list<string>
     */
    private function sorted(array $labels): array
    {
        natcasesort($labels);

        return array_values($labels);
    }
}
