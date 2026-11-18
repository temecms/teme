<?php

/**
 * Removes the backups the settings no longer keep (I-361), under the backup
 * lock, so never beside a backup: at each place in use, every run KeptRuns
 * doesn't keep is forgotten by its snapshots' IDs, restic's prune frees
 * their data, and restic's check follows. Each place is a run of its own,
 * and one failing doesn't stop the others.
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
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\ResticResult;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final readonly class PruneBackups
{
    /** Snapshots forgotten in one call, so a command line stays short on every system. */
    public const int FORGET_AT_ONCE = 100;

    public function __construct(
        private BackupLock $lock,
        private BackupPlaces $places,
        private Restic $restic,
        private BackupSnapshots $snapshots,
        private SettingsReader $settings,
        private StartBackupRun $start,
        private EndBackupRun $end,
    ) {}

    /**
     * One prune, now, and how it ended.
     *
     * @param  (Closure(string): void)|null  $progress  a sentence as each step starts
     *
     * @throws BackupAlreadyRunning
     */
    public function run(BackupTrigger $startedBy, ?string $actorId = null, ?Closure $progress = null): BackupResult
    {
        $say = $progress ?? function (string $step): void {};

        return $this->lock->during(function () use ($startedBy, $actorId, $say): BackupResult {
            $runId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::Prune, $startedBy, actorId: $actorId));
            $began = CarbonImmutable::now();

            try {
                $key = $this->places->machineKey();
                $problems = $key === null
                    ? ['Backups aren’t set up: there’s no machine key.']
                    : PlaceRepositories::during(function (PlaceRepositories $opened) use ($runId, $startedBy, $actorId, $say, $key): array {
                        $problems = [];

                        foreach ($this->places->inUse() as $place) {
                            // No place is pruned once the lock is lost (I-358).
                            if (! $this->lock->stillMine()) {
                                $problems[] = BackupSite::LOCK_LOST;

                                break;
                            }

                            $problem = $this->pruneAt($runId, $startedBy, $actorId, $place, $opened->repository($place, $key), $say);

                            if ($problem !== null) {
                                $problems[] = $problem;
                            }
                        }

                        return $problems;
                    });
            } catch (Throwable $unexpected) {
                Log::error('Backups: the run stopped on an error.', ['exception' => $unexpected]);

                try {
                    $this->end->execute(new EndBackupRunInput($runId, false, 'The backup stopped on an error Teme didn’t expect. The log has its details.', actorId: $actorId));
                } catch (Throwable) {
                    // StartBackupRun ends it later.
                }

                throw $unexpected;
            }

            $sentence = $problems === [] ? null : implode(' ', $problems);
            $this->end->execute(new EndBackupRunInput($runId, $sentence === null, $sentence, ['seconds' => self::seconds($began)], actorId: $actorId));

            return new BackupResult($runId, $sentence === null, $sentence);
        });
    }

    /**
     * One place's prune and check, each a run under the whole prune. The
     * problem in words, or null.
     *
     * @param  Closure(string): void  $say
     */
    private function pruneAt(string $runId, BackupTrigger $startedBy, ?string $actorId, BackupDestination $place, ResticRepository $repository, Closure $say): ?string
    {
        $say("Choosing which backups to keep at {$place->name}.");
        $pruneId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::Prune, $startedBy, $runId, $place->id, $actorId));
        $began = CarbonImmutable::now();
        $runs = $this->snapshots->runs($repository);

        if ($runs instanceof ResticResult) {
            return $this->failed($pruneId, $actorId, $place, $runs, 'listing backups', $began);
        }

        $kept = KeptRuns::choose(
            $runs,
            $this->settings->int(BackupSettings::GROUP, 'keep_daily'),
            $this->settings->int(BackupSettings::GROUP, 'keep_weekly'),
            $this->settings->int(BackupSettings::GROUP, 'keep_monthly'),
            $this->settings->string('general', 'timezone'),
        );
        [$forget, $runsForgotten] = [[], 0];

        foreach ($runs as $run) {
            // A run with no ID is Teme's tag on a stray snapshot: never kept.
            if ($run->runId === '' || ! in_array($run->runId, $kept, true)) {
                array_push($forget, ...$run->ids);
                $runsForgotten++;
            }
        }

        if ($forget !== []) {
            $say($runsForgotten === 1 ? "Removing 1 old backup from {$place->name}." : 'Removing '.number_format($runsForgotten)." old backups from {$place->name}.");

            foreach (array_chunk($forget, self::FORGET_AT_ONCE) as $ids) {
                $forgot = $this->restic->run($repository, ['forget', ...$ids]);

                if (! $forgot->succeeded()) {
                    return $this->failed($pruneId, $actorId, $place, $forgot, 'forgetting old backups', $began);
                }
            }
        }

        $pruned = $this->restic->run($repository, ['prune'], timeout: BackupSite::WORK_SECONDS);

        if (! $pruned->succeeded()) {
            return $this->failed($pruneId, $actorId, $place, $pruned, 'pruning', $began);
        }

        $this->end->execute(new EndBackupRunInput(
            $pruneId,
            true,
            figures: ['seconds' => self::seconds($began), 'runs_kept' => count($kept), 'runs_forgotten' => $runsForgotten, 'snapshots_forgotten' => count($forget)],
            places: [$place->id => PlaceOutcome::answered()],
            actorId: $actorId,
        ));

        // restic's check, straight after the prune (I-361).
        $say("Checking the backups at {$place->name}.");
        $checkId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::Check, $startedBy, $runId, $place->id, $actorId));
        $checkBegan = CarbonImmutable::now();
        $checked = $this->restic->run($repository, ['check']);

        if ($checked->succeeded()) {
            $this->end->execute(new EndBackupRunInput($checkId, true, figures: ['seconds' => self::seconds($checkBegan)], places: [$place->id => PlaceOutcome::answered()], actorId: $actorId));

            return null;
        }

        if ($checked->outcome === ResticOutcome::Failed) {
            Log::warning("Backups: checking at {$place->name}: {$checked->detail()}");
            $damage = "restic found damage in the backups at {$place->name}. Run restic check there to see it.";
            $this->end->execute(new EndBackupRunInput($checkId, false, $damage, ['seconds' => self::seconds($checkBegan)], places: [$place->id => PlaceOutcome::refused($damage, true)], actorId: $actorId));

            return $damage;
        }

        return $this->failed($checkId, $actorId, $place, $checked, 'checking', $checkBegan);
    }

    /**
     * Ends a place's run as failed, with restic's sentence, and logs restic's
     * own words (I-2725). The sentence.
     */
    private function failed(string $stepId, ?string $actorId, BackupDestination $place, ResticResult $result, string $doing, CarbonImmutable $began): string
    {
        Log::warning("Backups: {$doing} at {$place->name}: {$result->detail()}");
        $outcome = PlaceOutcome::failed($result, $place->name);
        $this->end->execute(new EndBackupRunInput($stepId, false, $outcome->error, ['seconds' => self::seconds($began)], places: [$place->id => $outcome], actorId: $actorId));

        return (string) $outcome->error;
    }

    private static function seconds(CarbonImmutable $began): int
    {
        return (int) round($began->diffInSeconds(CarbonImmutable::now(), true));
    }
}
