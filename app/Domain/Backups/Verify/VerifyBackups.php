<?php

/**
 * Proves the backups can be read and restored (I-362): nightly, a seventh of
 * each place's stored data read back by restic's check; weekly, the newest
 * backup's database restored into the spare database and compared with its
 * manifest, and 50 of its media files read back. Under the backup lock, so
 * never beside a backup, a prune or a restore; every row through the run
 * Operations.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Verify;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupMode;
use App\Domain\Backups\BackupRefused;
use App\Domain\Backups\BackupResult;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Concerns\AuthorizesBackupRuns;
use App\Domain\Backups\Operations\EndBackupRun;
use App\Domain\Backups\Operations\Input\EndBackupRunInput;
use App\Domain\Backups\Operations\Input\StartBackupRunInput;
use App\Domain\Backups\Operations\StartBackupRun;
use App\Domain\Backups\PlaceOutcome;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\DueVerification;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\PostgresTools;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\ResticResult;
use App\Domain\Backups\Tools\SnapshotRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use LogicException;
use SensitiveParameter;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final readonly class VerifyBackups
{
    use AuthorizesBackupRuns;

    /** A read-back reads one of this many parts of a place's data. */
    public const int SUBSETS = 7;

    /** Media files a test restore reads back. */
    public const int SAMPLE = 50;

    public const string NOT_SET_UP = 'Finish setting backups up first.';

    public const string NO_MACHINE_KEY = 'Backups aren’t set up: there’s no machine key.';

    public const string NO_PLACE = 'There’s no place to keep backups. Add one on Tools › Backups.';

    public const string UNEXPECTED = 'The check stopped on an error Teme didn’t expect. The log has its details.';

    public const string NOTHING_TO_TEST = 'There’s no finished backup to test yet.';

    public const string RESTORE_FAILED = 'pg_restore couldn’t restore the copy into the spare database. The log has its own words.';

    public function __construct(
        private BackupLock $lock,
        private BackupPlaces $places,
        private Restic $restic,
        private BackupSnapshots $snapshots,
        private PostgresTools $postgres,
        private DueVerification $due,
        private SettingsReader $settings,
        private StartBackupRun $start,
        private EndBackupRun $end,
    ) {}

    /**
     * The nightly read-back, now: at each place in use, the next seventh of
     * its data read back by restic's check. How it ended.
     *
     * @param  (Closure(string): void)|null  $progress  a sentence as each step starts
     *
     * @throws BackupAlreadyRunning|AuthorizationException|ValidationException
     */
    public function readBack(BackupTrigger $startedBy, ?string $actorId = null, ?Closure $progress = null): BackupResult
    {
        return $this->verify(BackupRunKind::ReadBack, $startedBy, $actorId, $progress ?? function (string $step): void {}, $this->readBackEverywhere(...));
    }

    /**
     * The weekly test restore, now: the newest finished backup's database
     * restored into the spare database and compared with its manifest, and
     * up to 50 of its media files read back and compared. How it ended.
     *
     * @param  (Closure(string): void)|null  $progress  a sentence as each step starts
     *
     * @throws BackupAlreadyRunning|AuthorizationException|ValidationException
     */
    public function testRestore(BackupTrigger $startedBy, ?string $actorId = null, ?Closure $progress = null): BackupResult
    {
        return $this->verify(BackupRunKind::TestRestore, $startedBy, $actorId, $progress ?? function (string $step): void {}, $this->testRestoreNewest(...));
    }

    /**
     * At most $count of $items, chosen at random.
     *
     * @template T
     *
     * @param  list<T>  $items
     *
     * @return list<T>
     */
    public static function sample(array $items, int $count): array
    {
        return count($items) <= $count ? $items : array_values(Arr::random($items, $count));
    }

    /**
     * Authorizes, refuses before set up, then runs $work under the lock as a
     * whole run of $kind, ending it however $work ends.
     *
     * @param  Closure(string): void  $say
     * @param  Closure(string, BackupTrigger, ?string, Closure(string): void): array{sentence: string|null, figures: array<string, int|string|null>, place: string|null, snapshot: string|null, places: array<string, PlaceOutcome>}  $work
     *
     * @throws BackupAlreadyRunning|AuthorizationException|ValidationException
     */
    private function verify(BackupRunKind $kind, BackupTrigger $startedBy, ?string $actorId, Closure $say, Closure $work): BackupResult
    {
        // Who may, then whether there's anything to check: before anything is written (I-2401).
        $this->authorize(new StartBackupRunInput($kind, $startedBy, actorId: $actorId));

        if (! $this->places->isSetUp()) {
            throw ValidationException::withMessages(['place' => [self::NOT_SET_UP]]);
        }

        // A restored site's places wait for a person's answer.
        if ($this->places->isPaused()) {
            throw ValidationException::withMessages(['place' => [BackupPlaces::PAUSED]]);
        }

        return $this->lock->during(function () use ($kind, $startedBy, $actorId, $say, $work): BackupResult {
            $this->endAbandoned($actorId);
            $runId = (string) $this->start->execute(new StartBackupRunInput($kind, $startedBy, actorId: $actorId));
            $began = CarbonImmutable::now();

            try {
                $ended = $work($runId, $startedBy, $actorId, $say);
            } catch (BackupRefused $refused) {
                $this->endQuietly($runId, $actorId, $refused->getMessage(), $refused->places, ['seconds' => self::seconds($began)]);

                return new BackupResult($runId, false, $refused->getMessage());
            } catch (Throwable $unexpected) {
                Log::error('Backups: the check stopped on an error.', ['exception' => $unexpected]);
                $this->endQuietly($runId, $actorId, self::UNEXPECTED);

                throw $unexpected;
            }

            $this->end->execute(new EndBackupRunInput(
                $runId,
                $ended['sentence'] === null,
                $ended['sentence'],
                [...$ended['figures'], 'seconds' => self::seconds($began)],
                $ended['snapshot'],
                $ended['place'],
                $ended['places'],
                $actorId,
            ));

            return new BackupResult($runId, $ended['sentence'] === null, $ended['sentence']);
        });
    }

    /**
     * Ends, as failed, every read-back and test restore, and every step of
     * one, still running: they work only under the backup lock, which this
     * process holds, so whatever ran them has ended. A backup's, a prune's
     * and a restore's runs are their own parts' to end (I-362).
     */
    private function endAbandoned(?string $actorId): void
    {
        if (! $this->lock->stillMine()) {
            throw new LogicException('A check starts only under the backup lock.');
        }

        // backup_runs_running_index: the few runs still running. Steps before their whole runs.
        $left = BackupRun::query()
            ->whereIn('kind', [BackupRunKind::ReadBack->value, BackupRunKind::TestRestore->value])
            ->where('state', BackupRunState::Running->value)
            ->orderByRaw('parent_id IS NULL')
            ->orderBy('started_at')
            ->pluck('id');

        foreach ($left as $id) {
            $this->end->execute(new EndBackupRunInput((string) $id, false, StartBackupRun::ABANDONED, actorId: $actorId));
        }
    }

    /**
     * The read-back at every place in use, each a step under the run; one
     * failing doesn't stop the others.
     *
     * @param  Closure(string): void  $say
     *
     * @return array{sentence: string|null, figures: array<string, int|string|null>, place: string|null, snapshot: string|null, places: array<string, PlaceOutcome>}
     */
    private function readBackEverywhere(string $runId, BackupTrigger $startedBy, ?string $actorId, Closure $say): array
    {
        $key = $this->places->machineKey() ?? throw new BackupRefused(self::NO_MACHINE_KEY);
        $inUse = $this->places->inUse();

        if ($inUse->isEmpty()) {
            throw new BackupRefused(self::NO_PLACE);
        }

        /** @var list<string> $problems */
        $problems = PlaceRepositories::during(function (PlaceRepositories $opened) use ($runId, $startedBy, $actorId, $say, $key, $inUse): array {
            $problems = [];

            foreach ($inUse as $place) {
                // No place is read once the lock is lost (I-358).
                if (! $this->lock->stillMine()) {
                    $problems[] = BackupSite::LOCK_LOST;

                    break;
                }

                $problem = $this->readBackAt($runId, $startedBy, $actorId, $place, $opened->repository($place, $key), $say);

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }

            return $problems;
        });

        return [
            'sentence' => $problems === [] ? null : implode(' ', $problems),
            'figures' => ['places' => $inUse->count()],
            'place' => null,
            'snapshot' => null,
            'places' => [],
        ];
    }

    /**
     * One place's read-back, a step under the run: asked to answer, then the
     * next seventh of its data read back. The problem in words, or null.
     *
     * @param  Closure(string): void  $say
     */
    private function readBackAt(string $runId, BackupTrigger $startedBy, ?string $actorId, BackupDestination $place, ResticRepository $repository, Closure $say): ?string
    {
        $subset = $this->due->nextSeventh($place->id);
        $say("Reading back part {$subset} of 7 of the backups at {$place->name}.");
        $stepId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::ReadBack, $startedBy, $runId, $place->id, $actorId));
        $began = CarbonImmutable::now();
        $outcome = $this->ask($place, $repository);

        if ($outcome === null) {
            $checked = $this->restic->run($repository, ['check', '--read-data-subset='.$subset.'/'.self::SUBSETS], timeout: BackupSite::WORK_SECONDS);

            if ($checked->succeeded()) {
                $outcome = PlaceOutcome::answered();
            } elseif ($checked->outcome === ResticOutcome::Failed) {
                // restic's check exits 1 when it finds damage.
                $this->log('reading back', $place, $checked);
                $outcome = PlaceOutcome::refused("restic found damage reading back part {$subset} of 7 of the backups at {$place->name}. Run restic check --read-data-subset={$subset}/7 there to see it.", true);
            } else {
                $outcome = $this->failed('reading back', $place, $checked);
            }
        }

        $this->end->execute(new EndBackupRunInput(
            $stepId,
            $outcome->error === null,
            $outcome->error,
            ['seconds' => self::seconds($began), 'subset' => $subset],
            places: [$place->id => $outcome],
            actorId: $actorId,
        ));

        return $outcome->error;
    }

    /**
     * The test restore of the newest finished backup (I-2406). The
     * spare database is emptied before and, whatever happens, after.
     *
     * @param  Closure(string): void  $say
     *
     * @return array{sentence: string|null, figures: array<string, int|string|null>, place: string|null, snapshot: string|null, places: array<string, PlaceOutcome>}
     */
    private function testRestoreNewest(string $runId, BackupTrigger $startedBy, ?string $actorId, Closure $say): array
    {
        // Host-run (TEME_BACKUPS=host): a temporary database, made now and dropped after; leftovers from a dead run go first.
        if (BackupMode::isHost()) {
            TemporaryDatabase::sweep();
            $spare = SpareDatabase::openTemporary();
        } else {
            $spare = SpareDatabase::open();
        }

        try {
            // backup_runs_kind_index: (kind, started_at DESC).
            $backup = BackupRun::query()
                ->where('kind', BackupRunKind::Backup->value)
                ->whereNull('parent_id')
                ->where('state', BackupRunState::Finished->value)
                ->orderByDesc('started_at')
                ->first() ?? throw new BackupRefused(self::NOTHING_TO_TEST);
            $key = $this->places->machineKey() ?? throw new BackupRefused(self::NO_MACHINE_KEY);
            $inUse = $this->places->inUse();

            if ($inUse->isEmpty()) {
                throw new BackupRefused(self::NO_PLACE);
            }

            $made = $backup->started_at->setTimezone($this->settings->string('general', 'timezone'))->format('j F Y \a\t H:i');

            return PlaceRepositories::during(function (PlaceRepositories $opened) use ($spare, $backup, $key, $inUse, $made, $say): array {
                $outcomes = [];
                [$place, $repository, $run] = $this->holder($backup->id, $made, $inUse, $opened, $key, $outcomes, $say);
                $say("Reading the backup of {$made} at {$place->name}.");
                $manifest = $this->manifest($place, $repository, $run, $made, $outcomes);
                $files = VerifyFiles::make();

                try {
                    $database = $this->restoreDatabase($spare, $place, $repository, $run, $manifest, $files, $backup, $say, $outcomes);
                    $media = $this->readBackMedia($place, $repository, $run, $manifest, $files, $say);
                } finally {
                    $files->remove();
                }

                $problems = [];

                if ($database['differences'] !== []) {
                    Log::warning('Backups: the test restore differs from the manifest: '.implode(' ', $database['differences']));
                    $more = count($database['differences']) - 1;
                    $problems[] = "The test restore of the backup of {$made} didn’t match its manifest: {$database['differences'][0]}"
                        .match (true) {
                            $more === 0 => '',
                            $more === 1 => ' And 1 more difference, which the log lists.',
                            default => ' And '.number_format($more).' more differences, which the log lists.',
                        };
                }

                if ($media['problem'] !== null) {
                    $problems[] = $media['problem'];
                }

                return [
                    'sentence' => $problems === [] ? null : implode(' ', $problems),
                    'figures' => [
                        'backup_run' => $backup->id,
                        'tables' => $database['tables'],
                        'rows' => $database['rows'],
                        'differences' => count($database['differences']),
                        'media_files' => $media['checked'],
                        'media_wrong' => $media['wrong'],
                    ],
                    'place' => $place->id,
                    'snapshot' => $run->parts[BackupSnapshots::MANIFEST],
                    'places' => [...$outcomes, $place->id => PlaceOutcome::answered()],
                ];
            });
        } finally {
            $spare->close();
        }
    }

    /**
     * The first place in use that answers and holds the run $runId whole;
     * each that doesn't answer is noted in $outcomes.
     *
     * @param  Collection<int, BackupDestination>  $inUse
     * @param  array<string, PlaceOutcome>  $outcomes
     * @param  Closure(string): void  $say
     *
     * @return array{0: BackupDestination, 1: ResticRepository, 2: SnapshotRun}
     *
     * @throws BackupRefused when none does
     */
    private function holder(string $runId, string $made, Collection $inUse, PlaceRepositories $opened, #[SensitiveParameter] string $key, array &$outcomes, Closure $say): array
    {
        foreach ($inUse as $place) {
            $this->stillLocked();
            $say("Asking {$place->name} to answer.");
            $repository = $opened->repository($place, $key);
            $refused = $this->ask($place, $repository);

            if ($refused !== null) {
                $outcomes[$place->id] = $refused;

                continue;
            }

            $runs = $this->snapshots->runs($repository);

            if ($runs instanceof ResticResult) {
                $outcomes[$place->id] = $this->failed('listing backups', $place, $runs);

                continue;
            }

            foreach ($runs as $run) {
                if ($run->runId === $runId && $run->complete()) {
                    return [$place, $repository, $run];
                }
            }
        }

        throw new BackupRefused("The newest backup, of {$made}, isn’t whole at any place backups are kept.", $outcomes);
    }

    /**
     * The run's manifest, as the place holds it.
     *
     * @param  array<string, PlaceOutcome>  $outcomes
     *
     * @throws BackupRefused
     */
    private function manifest(BackupDestination $place, ResticRepository $repository, SnapshotRun $run, string $made, array $outcomes): Manifest
    {
        $read = $this->restic->run($repository, ['dump', $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE]);

        try {
            return Manifest::fromJson(json_encode($read->succeeded() ? $read->json() : null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (InvalidArgumentException|JsonException) {
            $outcome = $read->succeeded() ? PlaceOutcome::answered() : $this->failed('reading the manifest', $place, $read);

            throw new BackupRefused("The manifest of the backup of {$made} couldn’t be read from {$place->name}.", [...$outcomes, $place->id => $outcome]);
        }
    }

    /**
     * The dump fetched into the work folder, the spare database emptied and
     * filled by pg_restore, the dump removed, and the rows counted and
     * compared with the manifest.
     *
     * @param  array<string, PlaceOutcome>  $outcomes
     * @param  Closure(string): void  $say
     *
     * @return array{differences: list<string>, tables: int, rows: int}
     *
     * @throws BackupRefused
     */
    private function restoreDatabase(SpareDatabase $spare, BackupDestination $place, ResticRepository $repository, SnapshotRun $run, Manifest $manifest, VerifyFiles $files, BackupRun $backup, Closure $say, array $outcomes): array
    {
        $this->stillLocked();
        $say("Fetching the copy of the database from {$place->name}.");
        $needed = (int) ($backup->figures['database_bytes'] ?? 0);
        $free = disk_free_space($files->folder);

        if ($free !== false && $free < $needed) {
            throw new BackupRefused(sprintf('There isn’t room for the copy of the database: it needs %s under %s, and %s is free.', self::megabytes($needed), $files->folder, self::megabytes((int) $free)));
        }

        $dump = $files->path(BackupSnapshots::DUMP_FILE);
        // Into a file of the work folder, never through PHP's memory.
        $fetched = $this->restic->run($repository, ['dump', '--target', $dump, $run->parts[BackupSnapshots::DATABASE], BackupSnapshots::DUMP_FILE], timeout: BackupSite::WORK_SECONDS);

        if (! $fetched->succeeded()) {
            $outcome = $this->failed('fetching the database', $place, $fetched);

            throw new BackupRefused((string) $outcome->error, [...$outcomes, $place->id => $outcome]);
        }

        $this->stillLocked();
        $say("Emptying the spare database {$spare->name}.");
        $spare->empty();
        $say("Restoring the copy into {$spare->name}.");
        $error = $spare->restore($this->postgres, $dump);
        $files->forget(BackupSnapshots::DUMP_FILE);

        if ($error !== null) {
            Log::warning("Backups: restoring into the spare database: {$error}");

            throw new BackupRefused(self::RESTORE_FAILED, [...$outcomes, $place->id => PlaceOutcome::answered()]);
        }

        $say('Comparing its rows with the manifest.');
        $found = $spare->tableCounts();
        $database = $manifest->toArray()['database'] ?? null;
        $newest = is_array($database) && is_string($database['newest_migration'] ?? null) ? $database['newest_migration'] : null;

        return [
            'differences' => SpareDatabase::differences($manifest->tables(), $found, $newest, $spare->newestMigration()),
            'tables' => count($found),
            'rows' => array_sum($found),
        ];
    }

    /**
     * Up to 50 originals the manifest names, each fetched from the media
     * snapshot into the work folder, its SHA-256 compared, and removed.
     *
     * @param  Closure(string): void  $say
     *
     * @return array{checked: int, wrong: int, problem: string|null}
     */
    private function readBackMedia(BackupDestination $place, ResticRepository $repository, SnapshotRun $run, Manifest $manifest, VerifyFiles $files, Closure $say): array
    {
        $media = $manifest->toArray()['media'] ?? null;
        $roots = is_array($media) && is_array($media['snapshot_roots'] ?? null) ? $media['snapshot_roots'] : [];
        /** @var list<array{disk: string, path: string, sha256: string}> $originals */
        $originals = array_values(array_filter(
            is_array($media) && is_array($media['originals'] ?? null) ? $media['originals'] : [],
            // Only the roles the snapshot holds: a role in a bucket was left to the bucket (I-3456).
            fn (mixed $original): bool => is_array($original) && is_string($original['disk'] ?? null) && is_string($roots[$original['disk']] ?? null) && is_string($original['path'] ?? null) && is_string($original['sha256'] ?? null),
        ));
        $sample = self::sample($originals, self::SAMPLE);

        if ($sample === []) {
            return ['checked' => 0, 'wrong' => 0, 'problem' => null];
        }

        $say(count($sample) === 1 ? "Reading back 1 media file from {$place->name}." : 'Reading back '.number_format(count($sample))." media files from {$place->name}.");
        $wrong = [];

        foreach ($sample as $index => $original) {
            $this->stillLocked();
            $name = 'media-'.$index;
            $root = $roots[$original['disk']] ?? null;
            // Inside the snapshot without its leading slash, which restic on Windows can't dump (I-2736).
            $read = is_string($root)
                ? $this->restic->run($repository, ['dump', '--target', $files->path($name), $run->parts[BackupSnapshots::MEDIA], ltrim(rtrim($root, '/').'/'.$original['path'], '/')])
                : null;

            if ($read !== null && ! $read->succeeded()) {
                $this->log('reading back media', $place, $read);
            }

            if ($read === null || ! $read->succeeded() || hash_file('sha256', $files->path($name)) !== $original['sha256']) {
                $wrong[] = "{$original['disk']}:{$original['path']}";
            }

            $files->forget($name);
        }

        $count = number_format(count($sample));

        return [
            'checked' => count($sample),
            'wrong' => count($wrong),
            'problem' => match (true) {
                $wrong === [] => null,
                count($sample) === 1 => "The 1 media file read back from {$place->name} didn’t match the manifest: {$wrong[0]}.",
                count($wrong) === 1 => "1 of the {$count} media files read back from {$place->name} didn’t match the manifest: {$wrong[0]}.",
                count($wrong) === count($sample) => "None of the {$count} media files read back from {$place->name} matched the manifest, such as {$wrong[0]}.",
                default => number_format(count($wrong))." of the {$count} media files read back from {$place->name} didn’t match the manifest, such as {$wrong[0]}.",
            },
        ];
    }

    private static function megabytes(int $bytes): string
    {
        return number_format((int) ceil($bytes / 1_048_576)).' MB';
    }

    /**
     * Whether a place answers with the repository Teme made there: null when
     * it does, or what Teme saw (I-360).
     */
    private function ask(BackupDestination $place, ResticRepository $repository): ?PlaceOutcome
    {
        $config = $this->restic->run($repository, ['cat', 'config'], timeout: BackupSite::ANSWER_SECONDS);

        if (! $config->succeeded()) {
            return $this->failed('asking it to answer', $place, $config);
        }

        $answer = $config->json();

        return is_array($answer) && ($answer['id'] ?? null) === $place->repository_id
            ? null
            : PlaceOutcome::refused("{$place->name} holds other backups than the ones Teme made there.", true);
    }

    /**
     * restic's failure at a place, in its sentence; its own words go to the log.
     */
    private function failed(string $doing, BackupDestination $place, ResticResult $result): PlaceOutcome
    {
        $this->log($doing, $place, $result);

        return PlaceOutcome::failed($result, $place->name);
    }

    /**
     * restic's own words for the operator, every secret of the call hidden
     * (I-2725); people read the sentence.
     */
    private function log(string $doing, BackupDestination $place, ResticResult $result): void
    {
        Log::warning("Backups: {$doing} at {$place->name}: {$result->detail()}");
    }

    /**
     * Stops the check when this process no longer holds the backup lock.
     *
     * @throws BackupRefused
     */
    private function stillLocked(): void
    {
        if (! $this->lock->stillMine()) {
            throw new BackupRefused(BackupSite::LOCK_LOST);
        }
    }

    /**
     * Ends a run that stopped, as failed; when even that fails, the next
     * check ends it as abandoned.
     *
     * @param  array<string, PlaceOutcome>  $places
     * @param  array<string, int>  $figures
     */
    private function endQuietly(string $runId, ?string $actorId, string $sentence, array $places = [], array $figures = []): void
    {
        try {
            $this->end->execute(new EndBackupRunInput($runId, false, $sentence, $figures, places: $places, actorId: $actorId));
        } catch (Throwable) {
            // The next check ends it.
        }
    }

    private static function seconds(CarbonImmutable $began): int
    {
        return (int) round($began->diffInSeconds(CarbonImmutable::now(), true));
    }
}
