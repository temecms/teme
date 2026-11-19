<?php

/**
 * Backs the site up (the spec's BackupSite; I-358): under the backup lock,
 * at the first place in use that answers, one run of four restic snapshots:
 * the database as one moment saw it, dumped by pg_dump straight into
 * restic; the media disks' folders; the APP_KEY; and the manifest, last.
 * Every row it writes goes through StartBackupRun and EndBackupRun, each in
 * its own transaction, so a run is seen while it works and recorded however
 * it ends. teme:backup:run, teme:backup:due and teme:deploy call it inline.
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
use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\PostgresTools;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\ResticResult;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

final readonly class BackupSite
{
    /** How long a place may take to answer before the next is tried, in seconds. */
    public const int ANSWER_SECONDS = 60;

    /** How long a backup's or a copy's restic call may take, in seconds. */
    public const int WORK_SECONDS = 43_200;

    /** What a run that lost the backup lock says. */
    public const string LOCK_LOST = 'This stopped because another backup, or a removal of old backups, may have started at the same time.';

    public function __construct(
        private BackupLock $lock,
        private BackupPlaces $places,
        private BackupTools $tools,
        private PostgresTools $postgres,
        private Restic $restic,
        private StartBackupRun $start,
        private EndBackupRun $end,
        private BackupCheck $check,
    ) {}

    /**
     * One backup, now, and how it ended. A failure along the way is recorded
     * on the run and returned; what's thrown is a refusal before anything is
     * written: the lock held, the actor, or backups not set up.
     *
     * @param  (Closure(string): void)|null  $progress  a sentence as each step starts; a BackupRefused it throws stops the run there (I-104)
     *
     * @throws BackupAlreadyRunning
     */
    public function run(BackupTrigger $startedBy, ?string $actorId = null, ?Closure $progress = null): BackupResult
    {
        $say = $progress ?? function (string $step): void {};

        return $this->lock->during(function () use ($startedBy, $actorId, $say): BackupResult {
            // Authorization and setup are StartBackupRun's: a refusal writes nothing.
            $runId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::Backup, $startedBy, actorId: $actorId));
            $began = CarbonImmutable::now();

            try {
                return $this->backUp($runId, $startedBy, $actorId, $say, $began);
            } catch (BackupRefused $refused) {
                // Quietly: a run that lost the lock may already have been ended as abandoned.
                $this->endQuietly($runId, $actorId, $refused->getMessage(), $refused->places, ['seconds' => self::seconds($began)]);

                return new BackupResult($runId, false, $refused->getMessage());
            } catch (Throwable $unexpected) {
                Log::error('Backups: the run stopped on an error.', ['exception' => $unexpected]);
                $this->endQuietly($runId, $actorId, 'The backup stopped on an error Teme didn’t expect. The log has its details.');

                throw $unexpected;
            }
        });
    }

    /**
     * Stops the run when this process no longer holds the backup lock, as
     * before every step (I-358).
     *
     * @throws BackupRefused
     */
    private function stillLocked(): void
    {
        if (! $this->lock->stillMine()) {
            throw new BackupRefused(self::LOCK_LOST);
        }
    }

    /**
     * The backup itself: what must hold before anything is read, then the
     * run at the first place that answers. Throws BackupRefused with the
     * words for what stopped it.
     *
     * @param  Closure(string): void  $say
     */
    private function backUp(string $runId, BackupTrigger $startedBy, ?string $actorId, Closure $say, CarbonImmutable $began): BackupResult
    {
        $this->stillLocked();
        $say('Checking restic and PostgreSQL’s tools.');
        $tools = $this->tools->check();

        if (! $tools->ready()) {
            throw new BackupRefused(implode(' ', $tools->problems));
        }

        $roots = MediaInventory::roots();
        $key = $this->places->machineKey() ?? throw new BackupRefused('Backups aren’t set up: there’s no machine key.');
        $inUse = $this->places->inUse();

        if ($inUse->isEmpty()) {
            throw new BackupRefused('There’s no place to keep backups. Add one on Tools › Backups.');
        }

        return PlaceRepositories::during(function (PlaceRepositories $opened) use ($runId, $startedBy, $actorId, $say, $began, $roots, $key, $inUse): BackupResult {
            $outcomes = [];
            [$place, $repository] = $this->firstThatAnswers($inUse, $opened, $key, $outcomes, $say);
            $written = $this->write($runId, $place, $repository, $roots, $say, $outcomes);
            $sentence = $written['holes'] === [] ? null : implode(' ', $written['holes']);
            $this->stillLocked();

            $this->end->execute(new EndBackupRunInput(
                $runId,
                $sentence === null,
                $sentence,
                [...$written['figures'], 'seconds' => self::seconds($began)],
                $written['snapshot'],
                $place->id,
                [...$outcomes, $place->id => PlaceOutcome::answered()],
                $actorId,
            ));

            $problems = [];

            try {
                $problem = $this->checkAt($runId, $startedBy, $actorId, $place, $repository, $written['manifest'], $say);

                if ($problem !== null) {
                    $problems[] = $problem;
                }

                $holders = [['place' => $place, 'repository' => $repository]];

                foreach ($inUse as $other) {
                    if ($other->id === $place->id) {
                        continue;
                    }

                    // No copy once the lock is lost (I-358).
                    if (! $this->lock->stillMine()) {
                        $problems[] = self::LOCK_LOST;

                        break;
                    }

                    $target = $opened->repository($other, $key);
                    $problem = $this->copyTo($runId, $startedBy, $actorId, $other, $target, $holders, $say);

                    if ($problem === null) {
                        $holders[] = ['place' => $other, 'repository' => $target];
                        $problem = $this->checkAt($runId, $startedBy, $actorId, $other, $target, $written['manifest'], $say);
                    }

                    if ($problem !== null) {
                        $problems[] = $problem;
                    }
                }
            } catch (BackupRefused $stopped) {
                // Stopped between steps once the backup is recorded (I-104):
                // what's left is skipped, and the backup stands.
                $problems[] = $stopped->getMessage();
            }

            $missing = $written['figures']['missing_files'];

            return new BackupResult(
                $runId,
                $sentence === null,
                $sentence,
                $problems,
                $place->name,
                $written['figures']['media_files'],
                $missing,
                $written['other_holes'],
                $written['absent_folder'],
            );
        });
    }

    /**
     * The first place in use that answers with the repository Teme made
     * there (I-360); each that doesn't is noted in $outcomes.
     *
     * @param  Collection<int, BackupDestination>  $inUse
     * @param  array<string, PlaceOutcome>  $outcomes
     * @param  Closure(string): void  $say
     *
     * @return array{0: BackupDestination, 1: ResticRepository}
     *
     * @throws BackupRefused when none does
     */
    private function firstThatAnswers(Collection $inUse, PlaceRepositories $opened, #[SensitiveParameter] string $key, array &$outcomes, Closure $say): array
    {
        foreach ($inUse as $place) {
            $this->stillLocked();
            $say("Asking {$place->name} to answer.");
            $repository = $opened->repository($place, $key);
            $outcome = $this->ask($place, $repository);

            if ($outcome === null) {
                return [$place, $repository];
            }

            $outcomes[$place->id] = $outcome;
        }

        $why = implode(' ', array_map(fn (PlaceOutcome $outcome): string => (string) $outcome->error, $outcomes));

        throw new BackupRefused(trim('No place answered, so nothing was backed up. '.$why), $outcomes);
    }

    /**
     * The place a copy to $target reads from: one that holds this backup and
     * that restic can open beside it, a folder first, since every place can
     * be opened beside a folder (I-2450); null when none can.
     *
     * @param  list<array{place: BackupDestination, repository: ResticRepository}>  $holders
     *
     * @return array{place: BackupDestination, repository: ResticRepository}|null
     */
    public static function copySource(array $holders, ResticRepository $target): ?array
    {
        $possible = array_values(array_filter($holders, fn (array $holder): bool => PlaceRepositories::canOpenTogether($holder['repository'], $target)));

        foreach ($possible as $holder) {
            if ($holder['place']->kind === BackupDestinationKind::Folder) {
                return $holder;
            }
        }

        return $possible[0] ?? null;
    }

    /**
     * How many steps a backup tells $progress of when $places places are in
     * use and each answers (I-104): the tools, asking the first place, the
     * four parts and the check after them, then a copy and a check at each
     * other place.
     */
    public static function steps(int $places): int
    {
        return 5 + 2 * max(1, $places);
    }

    /**
     * Copies to $place everything of Teme's it lacks, from a place that
     * holds this backup (I-360), recorded as a copy run under it. The
     * problem in words, or null when it copied.
     *
     * @param  list<array{place: BackupDestination, repository: ResticRepository}>  $holders
     * @param  Closure(string): void  $say
     */
    private function copyTo(string $runId, BackupTrigger $startedBy, ?string $actorId, BackupDestination $place, ResticRepository $target, array $holders, Closure $say): ?string
    {
        $say("Copying the backups to {$place->name}.");
        $copyId = (string) $this->start->execute(new StartBackupRunInput(BackupRunKind::Copy, $startedBy, $runId, $place->id, $actorId));
        $began = CarbonImmutable::now();
        $outcome = $this->ask($place, $target);

        if ($outcome === null) {
            $source = self::copySource($holders, $target);

            if ($source === null) {
                $outcome = PlaceOutcome::refused("Teme can’t copy to {$place->name} from any place that holds this backup, because restic would need two sets of S3 keys at once. Add a folder on this server, which every place can be copied from.", true);
            } else {
                // Everything of Teme's the place lacks, so a night it was away catches up too.
                $copied = $this->restic->run($target, ['copy', '--host', BackupSnapshots::HOST, '--tag', BackupSnapshots::TAG], from: $source['repository'], timeout: self::WORK_SECONDS);

                if (! $copied->succeeded()) {
                    $this->log('copying', $place, $copied);
                    $outcome = PlaceOutcome::failed($copied, $place->name);
                }
            }
        }

        $this->end->execute(new EndBackupRunInput(
            $copyId,
            $outcome === null,
            $outcome?->error,
            ['seconds' => self::seconds($began)],
            places: [$place->id => $outcome ?? PlaceOutcome::answered()],
            actorId: $actorId,
        ));

        return $outcome?->error;
    }

    /**
     * The check after the backup at one place (I-362), or the lock's loss
     * in words, when this process no longer holds it.
     *
     * @param  Closure(string): void  $say
     */
    private function checkAt(string $runId, BackupTrigger $startedBy, ?string $actorId, BackupDestination $place, ResticRepository $repository, Manifest $manifest, Closure $say): ?string
    {
        if (! $this->lock->stillMine()) {
            return self::LOCK_LOST;
        }

        $say("Checking the backup at {$place->name}.");

        return $this->check->at($runId, $startedBy, $actorId, $place, $repository, $manifest);
    }

    /**
     * Whether a place answers with the repository Teme made there: null when
     * it does, or what Teme saw.
     */
    private function ask(BackupDestination $place, ResticRepository $repository): ?PlaceOutcome
    {
        $config = $this->restic->run($repository, ['cat', 'config'], timeout: self::ANSWER_SECONDS);

        if (! $config->succeeded()) {
            $this->log('asking it to answer', $place, $config);

            return PlaceOutcome::failed($config, $place->name);
        }

        $answer = $config->json();

        return is_array($answer) && ($answer['id'] ?? null) === $place->repository_id
            ? null
            : PlaceOutcome::refused("{$place->name} holds other backups than the ones Teme made there.", true);
    }

    /**
     * The run's four parts at $place, in order, the manifest last (I-357):
     * the database as one moment saw it, the media, the APP_KEY and the
     * manifest. Returns the manifest, its snapshot, the figures, the holes
     * in words, whether one of them is other than media missing from this
     * server, and a media folder that wasn't there when the media was
     * counted; throws BackupRefused when a part can't be written.
     *
     * @param  array{originals: string|null, public: string|null}  $roots
     * @param  Closure(string): void  $say
     * @param  array<string, PlaceOutcome>  $outcomes
     *
     * @return array{manifest: Manifest, snapshot: string|null, figures: array{database_bytes: int, media_files: int, media_bytes: int, missing_files: int, media_in_bucket: int, bytes_added: int, tables: int, rows: int}, holes: list<string>, other_holes: bool, absent_folder: string|null}
     */
    private function write(string $runId, BackupDestination $place, ResticRepository $repository, array $roots, Closure $say, array $outcomes): array
    {
        $moment = DatabaseMoment::open();

        try {
            $media = MediaInventory::take($moment, $roots);
            $manifest = Manifest::make($runId, CarbonImmutable::now(), $moment, $media);
            $this->stillLocked();
            $say("Copying the database to {$place->name}.");
            // pg_dump imports the moment's snapshot as it starts, so the moment stays open until restic has the whole dump (I-358).
            $dump = $this->restic->run(
                $repository,
                ['backup', ...BackupSnapshots::partFlags($runId, BackupSnapshots::DATABASE), '--stdin-filename', BackupSnapshots::DUMP_FILE, '--stdin-from-command', '--', ...$this->postgres->dumpCommand($moment->snapshot)],
                environment: $this->postgres->environment(),
                timeout: self::WORK_SECONDS,
            );
        } finally {
            $moment->close();
        }

        if (! $dump->succeeded()) {
            $this->log('copying the database', $place, $dump);

            // restic names a program it ran that failed "command failed": the place answered, pg_dump didn't.
            throw str_contains($dump->detail(), 'command failed')
                ? new BackupRefused('pg_dump couldn’t copy the database, so nothing was backed up. The log has its own words.', [...$outcomes, $place->id => PlaceOutcome::answered()])
                : new BackupRefused($dump->sentence($place->name), [...$outcomes, $place->id => PlaceOutcome::failed($dump, $place->name)]);
        }

        [$holes, $otherHoles] = [[], false];

        if ($media->missingCount() > 0) {
            $holes[] = $media->missingCount() === 1
                ? "1 media file the database names wasn’t on this server, so no backup holds it: {$media->firstMissing()}."
                : number_format($media->missingCount())." media files the database names weren’t on this server, so no backup holds them, such as {$media->firstMissing()}.";
        }

        $this->stillLocked();
        $say($media->fileCount() === 1 ? "Backing up 1 media file to {$place->name}." : 'Backing up '.number_format($media->fileCount())." media files to {$place->name}.");
        $folders = $media->folders();
        // Both roles in a bucket: the media part says so, and holds no file (I-3456).
        $files = $folders === []
            ? $this->restic->run($repository, ['backup', ...BackupSnapshots::partFlags($runId, BackupSnapshots::MEDIA), '--stdin', '--stdin-filename', MediaInventory::IN_BUCKET_FILE], input: json_encode(['locations' => $media->locations()], JSON_THROW_ON_ERROR))
            : $this->restic->run($repository, ['backup', ...BackupSnapshots::partFlags($runId, BackupSnapshots::MEDIA), ...$folders], timeout: self::WORK_SECONDS);

        if ($files->outcome === ResticOutcome::Incomplete) {
            // A snapshot made without some files: a hole, but the best copy there is (I-2724).
            $this->log('backing up the media', $place, $files);
            $holes[] = "restic couldn’t read some media files at {$place->name}, so this backup is missing them. The log has restic’s own words.";
            $otherHoles = true;
        } elseif (! $files->succeeded()) {
            $this->log('backing up the media', $place, $files);

            throw new BackupRefused($files->sentence($place->name), [...$outcomes, $place->id => PlaceOutcome::failed($files, $place->name)]);
        }

        $this->stillLocked();
        $say('Keeping the site’s key with the backup.');
        $appKey = $this->restic->run(
            $repository,
            ['backup', ...BackupSnapshots::partFlags($runId, BackupSnapshots::APP_KEY), '--stdin', '--stdin-filename', BackupSnapshots::APP_KEY_FILE],
            // On standard input, which restic hides from every word it returns: never an argument, never a file (I-359).
            input: json_encode([
                'key' => (string) config('app.key'),
                'previous_keys' => array_values(array_filter((array) config('app.previous_keys'), is_string(...))),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        if (! $appKey->succeeded()) {
            $this->log('keeping the site’s key', $place, $appKey);

            throw new BackupRefused($appKey->sentence($place->name), [...$outcomes, $place->id => PlaceOutcome::failed($appKey, $place->name)]);
        }

        $this->stillLocked();
        $say('Writing the manifest.');
        $written = $this->restic->run(
            $repository,
            ['backup', ...BackupSnapshots::partFlags($runId, BackupSnapshots::MANIFEST), '--stdin', '--stdin-filename', BackupSnapshots::MANIFEST_FILE],
            input: $manifest->toJson(),
        );

        if (! $written->succeeded()) {
            $this->log('writing the manifest', $place, $written);

            throw new BackupRefused($written->sentence($place->name), [...$outcomes, $place->id => PlaceOutcome::failed($written, $place->name)]);
        }

        $snapshot = $written->message('summary')['snapshot_id'] ?? null;
        $tables = $manifest->tables();
        $added = 0;

        foreach ([$dump, $files, $appKey, $written] as $part) {
            $added += (int) ($part->message('summary')['data_added'] ?? 0);
        }

        return [
            'manifest' => $manifest,
            'snapshot' => is_string($snapshot) ? $snapshot : null,
            'figures' => [
                'database_bytes' => (int) ($dump->message('summary')['total_bytes_processed'] ?? 0),
                'media_files' => $media->fileCount(),
                'media_bytes' => $media->byteCount(),
                'missing_files' => $media->missingCount(),
                'media_in_bucket' => $media->bucketFileCount(),
                'bytes_added' => $added,
                'tables' => count($tables),
                'rows' => array_sum($tables),
            ],
            'holes' => $holes,
            'other_holes' => $otherHoles,
            'absent_folder' => $media->absentFolder(),
        ];
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
     * Ends a run that stopped, as failed. When even that fails (the run was
     * already ended as abandoned, or the database is gone), the next backup
     * or prune ends it.
     *
     * @param  array<string, PlaceOutcome>  $places
     * @param  array<string, int>  $figures
     */
    private function endQuietly(string $runId, ?string $actorId, string $sentence, array $places = [], array $figures = []): void
    {
        try {
            $this->end->execute(new EndBackupRunInput($runId, false, $sentence, $figures, places: $places, actorId: $actorId));
        } catch (Throwable) {
            // StartBackupRun ends it later.
        }
    }

    private static function seconds(CarbonImmutable $began): int
    {
        return (int) round($began->diffInSeconds(CarbonImmutable::now(), true));
    }
}
