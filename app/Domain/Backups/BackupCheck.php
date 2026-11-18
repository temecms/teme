<?php

/**
 * The check after every backup (I-362), at one place: the run is all there,
 * its four parts listed; its manifest reads back as it was written; and
 * restic's check finds nothing wrong. Recorded as a check run under the
 * backup, and the place turns failing when the check fails.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Operations\EndBackupRun;
use App\Domain\Backups\Operations\Input\EndBackupRunInput;
use App\Domain\Backups\Operations\Input\StartBackupRunInput;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\ResticResult;
use App\Domain\Backups\Tools\SnapshotRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final readonly class BackupCheck
{
    public function __construct(
        private Restic $restic,
        private BackupSnapshots $snapshots,
        private StartBackupRun $start,
        private EndBackupRun $end,
    ) {}

    /**
     * Checks the backup $parentId at $place. The problem in words, or null
     * when the backup is whole there.
     */
    public function at(string $parentId, BackupTrigger $startedBy, ?string $actorId, BackupDestination $place, ResticRepository $repository, Manifest $manifest): ?string
    {
        $checkId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::Check, $startedBy, $parentId, $place->id, $actorId));
        $began = CarbonImmutable::now();
        $outcome = $this->outcome($parentId, $place, $repository, $manifest);

        $this->end->execute(new EndBackupRunInput(
            $checkId,
            $outcome->error === null,
            $outcome->error,
            ['seconds' => (int) round($began->diffInSeconds(CarbonImmutable::now(), true))],
            places: [$place->id => $outcome],
            actorId: $actorId,
        ));

        return $outcome->error;
    }

    private function outcome(string $runId, BackupDestination $place, ResticRepository $repository, Manifest $manifest): PlaceOutcome
    {
        $runs = $this->snapshots->runs($repository);

        if ($runs instanceof ResticResult) {
            return $this->failed('listing backups', $place, $runs);
        }

        $run = array_values(array_filter($runs, fn (SnapshotRun $run): bool => $run->runId === $runId))[0] ?? null;

        if ($run === null || ! $run->complete()) {
            return PlaceOutcome::refused("The backup at {$place->name} isn’t all there: some of its parts are missing.", true);
        }

        $read = $this->restic->run($repository, ['dump', $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE]);

        if (! $read->succeeded()) {
            return $this->failed('checking', $place, $read);
        }

        // Both sides decoded from JSON, so equal exactly when the bytes say the same.
        if ($read->json() !== json_decode($manifest->toJson(), true)) {
            return PlaceOutcome::refused("The manifest read back from {$place->name} isn’t the one Teme wrote.", true);
        }

        $checked = $this->restic->run($repository, ['check']);

        if ($checked->outcome === ResticOutcome::Failed) {
            Log::warning("Backups: checking at {$place->name}: {$checked->detail()}");

            return PlaceOutcome::refused("restic found damage in the backups at {$place->name}. Run restic check there to see it.", true);
        }

        return $checked->succeeded() ? PlaceOutcome::answered() : $this->failed('checking', $place, $checked);
    }

    private function failed(string $doing, BackupDestination $place, ResticResult $result): PlaceOutcome
    {
        // restic's own words, every secret of the call hidden (I-2725).
        Log::warning("Backups: {$doing} at {$place->name}: {$result->detail()}");

        return PlaceOutcome::failed($result, $place->name);
    }
}
