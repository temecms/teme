<?php

/**
 * The preflight (I-363): finds the backup to restore and refuses, before
 * anything is written, with every problem in words: the tools, this
 * server's database, the place and its backup, the manifest and the key,
 * compatibility, the media's folders, room, and the safety backup.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\SnapshotRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class RestorePoints
{
    public const string NO_PLACE = 'There’s no place backups are kept, so there’s nothing to restore from. On a new server, name the place with --kind.';

    public function __construct(
        private BackupTools $tools,
        private BackupSetup $setup,
        private BackupPlaces $places,
        private BackupSnapshots $snapshots,
        private Restic $restic,
        private SettingsReader $settings,
    ) {}

    /**
     * The backup the request names, ready to restore; RestoreRefused with
     * every problem when it can't be restored here.
     *
     * @param  array{run: string, safety: string|null, safety_place: array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null, safety_missing?: int, since: string}|null  $unfinished  the marker of a restore that didn't finish
     * @param  (Closure(string): void)|null  $say
     *
     * @throws RestoreRefused
     */
    public function find(RestoreRequest $request, PlaceRepositories $open, ?array $unfinished = null, ?Closure $say = null): RestorePoint
    {
        $say ??= function (string $step): void {};

        $say('Checking restic and PostgreSQL’s tools.');
        $tools = $this->tools->check();

        if (! $tools->ready()) {
            throw new RestoreRefused($tools->problems);
        }

        $database = self::databaseProblems($unfinished !== null);

        if ($database !== []) {
            throw new RestoreRefused($database);
        }

        $placesBefore = $this->setup->placesInUse()->map(fn (BackupDestination $place): array => ['name' => $place->name, 'repository_id' => $place->repository_id])->values()->all();
        [$placeName, $placeRepositoryId, $repository, $run] = $this->place($request, $open, $unfinished, $say);
        $say('Reading the backup of '.$this->inSiteZone($run->time)->format('j F Y \a\t H:i').'.');
        $manifest = $this->manifest($repository, $run, $placeName);
        $appKey = $this->appKey($repository, $run, $placeName);

        $compatible = RestoreCompatibility::check($manifest);
        $media = MediaRestore::pairs($manifest);
        $problems = [...$compatible['problems'], ...$media['problems']];
        $room = RestoreRoom::problem($this->dumpBytes($repository, $run), self::freeAt(storage_path('app')), storage_path('app/restore'), 'database');

        if ($room !== null) {
            $problems[] = $room;
        }

        foreach ($media['pairs'] as $pair) {
            $room = RestoreRoom::problem(self::mediaNeed($manifest, $pair, count($media['pairs'])), self::freeAt($pair['to']), $pair['to'], 'media');

            if ($room !== null) {
                $problems[] = $room;
            }
        }

        // A half-restored site isn't the site: its safety backup was made before (docs/architecture/backups.md).
        $safety = $unfinished === null && ! $request->withoutSafetyBackup && self::siteHasData();

        if ($safety && ! $this->places->isSetUp()) {
            $problems[] = 'This site has data, and backups aren’t set up, so Teme can’t back it up before replacing it. Add --without-safety-backup to replace it anyway.';
        } elseif ($safety && $this->places->isPaused()) {
            $problems[] = 'This site has data, and its backups are paused since an earlier restore, so Teme can’t back it up before replacing it. Answer that restore first with php artisan teme:backup:restored, or add --without-safety-backup.';
        }

        if ($problems !== []) {
            throw new RestoreRefused($problems);
        }

        // Where a role the backup left in a bucket is (I-3456).
        $notes = [...$compatible['notes'], ...$media['notes']];

        if ($unfinished !== null) {
            $since = $this->inSiteZone(CarbonImmutable::parse($unfinished['since']))->format('j F Y \a\t H:i');
            $notes[] = "A restore of {$unfinished['run']} didn’t finish, and the site has been closed since {$since}. This restore finishes the job."
                .($unfinished['safety'] === null ? '' : " The site as it was before is backup {$unfinished['safety']}.");
        }

        $data = $manifest->toArray();
        $tables = $manifest->tables();
        $madeAt = CarbonImmutable::parse((string) $data['made_at']);

        return new RestorePoint(
            new RestorePlan(
                $run->runId,
                $madeAt,
                $this->inSiteZone($madeAt)->format('j F Y \a\t H:i'),
                $placeName,
                (string) $data['teme'],
                count($tables),
                array_sum($tables),
                (int) ($data['media']['files'] ?? 0),
                (int) ($data['media']['bytes'] ?? 0),
                $safety,
                $unfinished,
                $notes,
            ),
            $repository,
            $run,
            $manifest,
            $appKey,
            $placeRepositoryId,
            $media['pairs'],
            $placesBefore,
            $request->from !== null,
        );
    }

    /**
     * Whether this site holds anything a restore would destroy: a person, an
     * item or a media item.
     */
    public static function siteHasData(): bool
    {
        foreach (['users', 'contents', 'media'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * This server's database: migrated by this Teme, named plainly, and able
     * to hold maintenance mode's flag across the restore. A half-restored
     * database may lack migrations, when they were what stopped it: the
     * restore replaces it and runs them after.
     *
     * @return list<string>
     */
    private static function databaseProblems(bool $unfinished): array
    {
        $problems = [];

        if (! $unfinished && RestoreCompatibility::pendingMigrations() > 0) {
            $problems[] = 'This server’s database hasn’t run this Teme’s migrations. Run php artisan migrate first.';
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', (string) (DB::connection()->getConfig()['database'] ?? '')) !== 1) {
            $problems[] = 'Teme restores only into a database whose name is letters, digits, dots, hyphens and underscores.';
        }

        $store = (string) config('app.maintenance.store');

        if (config('app.maintenance.driver') === 'cache' && config("cache.stores.{$store}.driver") === 'database') {
            $table = (string) (config("cache.stores.{$store}.table") ?? 'cache');

            if (! Schema::hasTable($table)) {
                $problems[] = "Maintenance mode keeps its flag in the {$table} table, which this database doesn’t have. Run php artisan migrate first.";
            }
        }

        return $problems;
    }

    /**
     * The place, its repository and the run to restore: the place named, or
     * each in use (paused ones too: a restore only reads them) until one
     * answers with a complete run. Going back to an unfinished restore's
     * safety backup, its place as the marker keeps it, known here or not.
     *
     * @param  array{run: string, safety: string|null, safety_place: array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null, safety_missing?: int, since: string}|null  $unfinished
     * @param  Closure(string): void  $say
     *
     * @return array{0: string, 1: string, 2: ResticRepository, 3: SnapshotRun} the place's name and repository ID, its repository, and the run
     */
    private function place(RestoreRequest $request, PlaceRepositories $open, ?array $unfinished, Closure $say): array
    {
        if ($request->from !== null) {
            return $this->namedPlace($request, $request->from, $open, $say);
        }

        $candidates = $this->setup->placesInUse();
        $marked = $unfinished['safety_place'] ?? null;

        if ($request->place === null && $marked !== null && $request->runId !== null && $request->runId === ($unfinished['safety'] ?? null)) {
            $known = $candidates->first(fn (BackupDestination $place): bool => $place->repository_id === $marked['repository_id']);

            // Only a folder opens without the credentials the old database held (docs/architecture/backups.md).
            if ($known === null && $marked['kind'] !== BackupDestinationKind::Folder->value) {
                throw new RestoreRefused(["The safety backup {$request->runId} is at {$marked['name']}, which the restored database doesn’t know. Name that place on the command line with --kind and --key-file."]);
            }

            // Never saved: the place as the marker describes it, for this restore to read.
            $candidates = collect([$known ?? new BackupDestination([
                'name' => $marked['name'],
                'kind' => BackupDestinationKind::Folder,
                'location' => $marked['location'],
                'repository_id' => $marked['repository_id'],
            ])]);
        }

        if ($candidates->isEmpty()) {
            throw new RestoreRefused([self::NO_PLACE]);
        }

        if ($request->place !== null) {
            $named = $candidates->first(fn (BackupDestination $place): bool => $place->id === $request->place || mb_strtolower($place->name) === mb_strtolower($request->place));
            $candidates = $named === null ? throw new RestoreRefused(["There’s no place called “{$request->place}”."]) : collect([$named]);
        }

        $key = $this->places->machineKey() ?? throw new RestoreRefused(['Backups aren’t set up: there’s no machine key.']);
        $why = [];

        foreach ($candidates as $place) {
            $say("Asking {$place->name} to answer.");
            $repository = $open->repository($place, $key);
            $config = $this->restic->run($repository, ['cat', 'config'], timeout: PlaceRepositories::ANSWER_SECONDS);

            if (! $config->succeeded()) {
                Log::warning("Backups: asking it to answer at {$place->name}: {$config->detail()}");
                $why[] = $config->sentence($place->name);

                continue;
            }

            $answer = $config->json();

            if (! is_array($answer) || ($answer['id'] ?? null) !== $place->repository_id) {
                $why[] = "{$place->name} holds other backups than the ones Teme made there.";

                continue;
            }

            $runs = $this->snapshots->runs($repository);

            if (! is_array($runs)) {
                Log::warning("Backups: asking it to answer at {$place->name}: {$runs->detail()}");
                $why[] = $runs->sentence($place->name);

                continue;
            }

            $run = array_values(array_filter($runs, fn (SnapshotRun $run): bool => $run->complete() && ($request->runId === null || $run->runId === $request->runId)))[0] ?? null;

            if ($run !== null) {
                return [$place->name, $place->repository_id, $repository, $run];
            }

            $why[] = $request->runId === null
                ? "{$place->name} has no complete backup to restore."
                : "{$place->name} has no complete backup {$request->runId}. php artisan teme:backup:list shows the ones it has.";
        }

        throw new RestoreRefused($request->place !== null ? $why : ['No place answered with a backup to restore. '.implode(' ', $why)]);
    }

    /**
     * A place named on a new server's command line, opened with the key typed
     * (D20, D21): its address checked first, its repository ID taken from the
     * answer, since this database knows none.
     *
     * @param  Closure(string): void  $say
     *
     * @return array{0: string, 1: string, 2: ResticRepository, 3: SnapshotRun}
     *
     * @throws RestoreRefused
     */
    private function namedPlace(RestoreRequest $request, NewPlace $from, PlaceRepositories $open, Closure $say): array
    {
        $problems = array_values($from->problems());

        if ($problems !== []) {
            throw new RestoreRefused($problems);
        }

        $name = $from->name();
        $say("Asking {$name} to answer.");
        $repository = $open->repository($from, (string) $request->key());
        $config = $this->restic->run($repository, ['cat', 'config'], timeout: PlaceRepositories::ANSWER_SECONDS);

        if (! $config->succeeded()) {
            Log::warning("Backups: asking it to answer at {$name}: {$config->detail()}");

            throw new RestoreRefused([$config->sentence($name)]);
        }

        $answer = $config->json();
        $id = is_array($answer) ? ($answer['id'] ?? null) : null;

        if (! is_string($id)) {
            throw new RestoreRefused(["{$name} holds other backups than the ones Teme made there."]);
        }

        $runs = $this->snapshots->runs($repository);

        if (! is_array($runs)) {
            Log::warning("Backups: asking it to answer at {$name}: {$runs->detail()}");

            throw new RestoreRefused([$runs->sentence($name)]);
        }

        $run = array_values(array_filter($runs, fn (SnapshotRun $run): bool => $run->complete() && ($request->runId === null || $run->runId === $request->runId)))[0] ?? null;

        if ($run === null) {
            throw new RestoreRefused([$request->runId === null
                ? "{$name} has no complete backup to restore."
                : "{$name} has no complete backup {$request->runId}. php artisan teme:backup:list shows the ones it has."]);
        }

        return [$name, $id, $repository, $run];
    }

    /**
     * @throws RestoreRefused
     */
    private function manifest(ResticRepository $repository, SnapshotRun $run, string $place): Manifest
    {
        $read = $this->restic->run($repository, ['dump', $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE], timeout: PlaceRepositories::ANSWER_SECONDS);
        $data = $read->succeeded() ? $read->json() : null;

        if (! is_array($data)) {
            Log::warning("Backups: reading the manifest at {$place}: {$read->detail()}");

            throw new RestoreRefused(["This backup’s manifest couldn’t be read from {$place}."]);
        }

        try {
            return Manifest::fromJson(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (InvalidArgumentException) {
            throw new RestoreRefused(['This backup’s manifest is in a format this Teme can’t read. Restore it with the Teme that made it, or a later one.']);
        }
    }

    /**
     * @throws RestoreRefused
     */
    private function appKey(ResticRepository $repository, SnapshotRun $run, string $place): BackupAppKey
    {
        // On restic's standard output, into memory only (I-359).
        $read = $this->restic->run($repository, ['dump', $run->parts[BackupSnapshots::APP_KEY], BackupSnapshots::APP_KEY_FILE], timeout: PlaceRepositories::ANSWER_SECONDS);

        try {
            return BackupAppKey::fromArray($read->succeeded() && is_array($read->json()) ? $read->json() : []);
        } catch (InvalidArgumentException) {
            Log::warning("Backups: reading the site’s key at {$place}: {$read->detail()}");

            throw new RestoreRefused(["This backup’s APP_KEY couldn’t be read from {$place}."]);
        }
    }

    /**
     * The dump's size, as restic lists it; 0 when it won't say, and then the
     * download is what fails.
     */
    private function dumpBytes(ResticRepository $repository, SnapshotRun $run): int
    {
        $listed = $this->restic->run($repository, ['ls', $run->parts[BackupSnapshots::DATABASE]], timeout: PlaceRepositories::ANSWER_SECONDS);

        foreach ($listed->messages() as $node) {
            if (($node['name'] ?? null) === BackupSnapshots::DUMP_FILE && ($node['type'] ?? null) === 'file') {
                return (int) ($node['size'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * What a media folder still needs written, by the manifest: its role's
     * originals not there at their size, and the same share of the variants,
     * which follow their originals.
     *
     * @param  array{role: string, from: string, to: string}  $pair
     */
    private static function mediaNeed(Manifest $manifest, array $pair, int $pairs): int
    {
        $media = (array) ($manifest->toArray()['media'] ?? []);
        [$all, $missing, $need] = [0, 0, 0];

        foreach ((array) ($media['originals'] ?? []) as $original) {
            $all++;
            $file = $pair['to'].DIRECTORY_SEPARATOR.$original['path'];
            $mine = $pairs === 1 || $original['disk'] === $pair['role'];

            if (! is_file($file) || filesize($file) !== (int) $original['bytes']) {
                $missing++;
                $need += $mine ? (int) $original['bytes'] : 0;
            }
        }

        if ($pair['role'] === 'public' && $all > 0) {
            $need += (int) round((int) ($media['variants']['bytes'] ?? 0) * $missing / $all);
        }

        return $need;
    }

    /**
     * The free bytes on the disk that holds $folder, or the nearest folder above it that exists.
     */
    private static function freeAt(string $folder): float|false
    {
        while (! is_dir($folder) && dirname($folder) !== $folder) {
            $folder = dirname($folder);
        }

        return @disk_free_space($folder);
    }

    private function inSiteZone(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone($this->settings->string('general', 'timezone'));
    }
}
