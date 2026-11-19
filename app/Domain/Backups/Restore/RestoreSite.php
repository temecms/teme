<?php

/**
 * Restores the site from a backup (the spec's RestoreSite; I-363): the
 * preflight first, refusing before anything is written; then, holding the
 * deploy lock, and refused while a deploy holds it, the dump fetched while
 * the site is open; then, behind maintenance mode, a safety backup of the
 * site as it is (accepted when its only hole is media files this server
 * already lacked, which the restore's notes and figures then count, and
 * refused when every media file is missing, which is how an unmounted
 * volume looks) and, under the backup lock as well, the database, the
 * media, the caches and the records, with the places paused until a person
 * says whether this server is now the site. The deploy lock is taken before
 * the backup lock, as teme:deploy takes them. A stop always says how to
 * finish, by running it again, or go back, by restoring the safety backup.
 * teme:backup:restore calls it inline.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupResult;
use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Operations\BeginRestore;
use App\Domain\Backups\Operations\EndRestore;
use App\Domain\Backups\Operations\Input\BeginRestoreInput;
use App\Domain\Backups\Operations\Input\EndRestoreInput;
use App\Domain\Backups\Operations\Input\SettleRestoredSiteInput;
use App\Domain\Backups\Operations\SettleRestoredSite;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Domain\Contents\Operations\Input\RebuildDocumentDerivationsInput;
use App\Domain\Contents\Operations\RebuildDocumentDerivations;
use App\Domain\Deployment\DeployLock;
use App\Domain\Media\Queries\ContentIdsInOrder;
use App\Domain\Media\Queries\ImagesNeedingVariants;
use App\Domain\Security\OldKeys;
use App\Domain\Security\Operations\Input\ReencryptSecretsInput;
use App\Domain\Security\Operations\ReencryptSecrets;
use App\Domain\Support\Security\Operator;
use App\Domain\Updates\Operations\EndTemeUpdate;
use App\Domain\Updates\Operations\Input\EndTemeUpdateInput;
use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Requests\UpdateStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class RestoreSite
{
    /** How long fetching the dump, pg_restore or one media folder may take, in seconds. */
    public const int WORK_SECONDS = 43_200;

    public const string OPERATOR_ONLY = 'Only the operator at the server restores a site.';

    public const string LOCK_LOST = 'The backup lock was lost, so the restore stopped: a backup or a prune may have started beside it.';

    public const string DEPLOY_RUNNING = 'teme:deploy is running on this database, so nothing was changed. Run the restore again once it has finished.';

    public const string DEPLOY_LOCK_LOST = 'The deploy lock was lost, so the restore stopped before running migrations: a deploy may have started beside it.';

    /** Content items a chunk, when derived values are filled in. */
    private const int CHUNK = 500;

    public function __construct(
        private DeployLock $deployLock,
        private BackupLock $lock,
        private RestorePoints $points,
        private BackupSite $backups,
        private DatabaseRestore $database,
        private MediaRestore $media,
        private PageCache $pages,
        private BeginRestore $begin,
        private SettleRestoredSite $settle,
        private EndRestore $end,
        private RebuildDocumentDerivations $derivations,
        private ReencryptSecrets $reencrypt,
        private MediaCheck $check,
        private SmokeTest $smoke,
        private TemeUpdates $updates,
        private EndTemeUpdate $endUpdate,
    ) {}

    /**
     * What a restore would do, or RestoreRefused with every problem; writes nothing.
     *
     * @param  (Closure(string): void)|null  $say
     *
     * @throws RestoreRefused|AuthorizationException
     */
    public function preflight(RestoreRequest $request, ?Closure $say = null): RestorePlan
    {
        self::authorize();

        return PlaceRepositories::during(fn (PlaceRepositories $open): RestorePlan => $this->points->find($request, $open, MaintenanceWindow::unfinished(), $say)->plan);
    }

    /**
     * Restores the site, and says how it ended. What's thrown is a refusal
     * before anything is written: the preflight's, the actor's, or a running
     * deploy's. Every other stop, another backup holding the lock among
     * them, is a result.
     *
     * @param  (Closure(string): void)|null  $progress  a sentence as each step starts
     *
     * @throws RestoreRefused|AuthorizationException
     */
    public function run(RestoreRequest $request, ?Closure $progress = null): RestoreResult
    {
        self::authorize();
        $say = $progress ?? function (string $step): void {};
        $began = CarbonImmutable::now();

        return PlaceRepositories::during(function (PlaceRepositories $open) use ($request, $say, $began): RestoreResult {
            $unfinished = MaintenanceWindow::unfinished();
            $point = $this->points->find($request, $open, $unfinished, $say);

            // Before the backup lock, as teme:deploy takes them, and never waited for: a deploy holding it waits on the backup lock (I-377).
            return $this->deployLock->during(
                0,
                function (): never {
                    throw new RestoreRefused([self::DEPLOY_RUNNING]);
                },
                function () use ($point, $unfinished, $say, $began): RestoreResult {
                    $this->begin->execute(new BeginRestoreInput($point->plan->runId, $point->plan->place, $point->plan->madeAt));

                    return $this->restore($point, $unfinished, $say, $began);
                },
            );
        });
    }

    /**
     * The restore itself, in the designed order: the dump fetched while
     * the site is open; the site closed; the safety backup, which holds
     * whatever was edited meanwhile; then everything else under the lock.
     * Each stop is said by how far it got (I-363).
     *
     * @param  array{run: string, safety: string|null, safety_place: array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null, safety_missing?: int, since: string}|null  $unfinished
     * @param  Closure(string): void  $say
     */
    private function restore(RestorePoint $point, ?array $unfinished, Closure $say, CarbonImmutable $began): RestoreResult
    {
        $plan = $point->plan;
        $files = RestoreFiles::make();
        $stage = RestoreStage::SiteUntouched;
        [$window, $restoreId, $removed, $ran, $secrets, $checked] = [null, null, [], 0, [], null];
        [$safety, $safetyPlace, $missing] = [$unfinished['safety'] ?? null, $unfinished['safety_place'] ?? null, $unfinished['safety_missing'] ?? 0];

        try {
            // While the site is still open: nothing here changes it.
            $say("Fetching the copy of the database from {$plan->place}.");
            $this->database->download($point->repository, $point->run->parts[BackupSnapshots::DATABASE], $files->path(RestoreFiles::DUMP), $plan->place);
            $contents = $this->database->contents($files->path(RestoreFiles::DUMP));
            $kept = DatabaseRestore::keptTables();
            $files->write(RestoreFiles::LIST, implode("\n", DatabaseRestore::useList($contents, $kept, DatabaseRestore::indexesOf($kept), DatabaseRestore::installedExtensions()))."\n");
            $lacking = DatabaseRestore::tablesTheDumpLacks($contents, $kept);

            $say('Closing the site for maintenance.');
            $window = MaintenanceWindow::open(['run' => $plan->runId, 'safety' => $safety, 'safety_place' => $safetyPlace, ...($missing > 0 ? ['safety_missing' => $missing] : []), 'since' => $began->utc()->toIso8601String()]);

            if ($plan->safetyBackup) {
                $say('Making a safety backup of the site as it is now.');
                // The site is closed, so it holds what was edited during the download. Its own lock: BackupLock::during() doesn't nest (I-358).
                $copy = $this->backups->run(BackupTrigger::Command, null, $say);

                // Media this server already lacks can't be in any backup taken here: the safety copy stands without them.
                if (! $copy->succeeded && ! $copy->onlyMissingFiles()) {
                    throw new RestoreStopped('The safety backup failed: '.$copy->sentence);
                }

                if ($copy->onlyMissingFiles() && $copy->mediaLooksUnmounted()) {
                    throw new RestoreStopped(self::unmounted($copy));
                }

                [$safety, $safetyPlace, $missing] = [$copy->runId, $this->placeOf($copy->place), $copy->missingFiles];
                $window->record($safety, $safetyPlace, $missing);

                if ($missing > 0) {
                    $say($missing === 1
                        ? __('The safety backup lacks 1 media file the database names: it was already missing from this server.')
                        : __('The safety backup lacks :count media files the database names: they were already missing from this server.', ['count' => number_format($missing)]));
                }
            }

            $this->lock->during(function () use ($point, $plan, $files, $window, $lacking, $safety, $say, $began, &$stage, &$removed, &$ran, &$restoreId, &$secrets, &$checked): void {
                $say('Clearing the page cache.');
                // While this database's page-cache tables still name the stored files (docs/architecture/page-cache.md).
                $this->purgePages();

                if ($lacking !== []) {
                    $this->stillLocked();
                    $say(count($lacking) === 1 ? 'Removing 1 table the backup doesn’t have.' : 'Removing '.count($lacking).' tables the backup doesn’t have.');
                    $this->database->dropTables($lacking);
                    [$stage, $removed] = [RestoreStage::TablesDropped, $lacking];
                }

                $this->stillLocked();
                $say('Restoring the database.');

                try {
                    $this->database->restore($files->path(RestoreFiles::DUMP), $files->path(RestoreFiles::LIST));
                } catch (RestoreStopped $failed) {
                    throw $failed;
                } catch (Throwable $unknown) {
                    // pg_restore may have committed before this broke: the database may be the backup's.
                    $stage = RestoreStage::DatabaseRestored;

                    throw $unknown;
                }

                $stage = RestoreStage::DatabaseRestored;
                $this->database->emptyCache();
                $window->reassert();

                if (RestoreCompatibility::pendingMigrations() > 0) {
                    $say('Running this Teme’s newer migrations.');

                    // A lost session lost the lock with it: a deploy may be migrating now (I-377).
                    if (! $this->deployLock->stillHeld()) {
                        throw new RestoreStopped(self::DEPLOY_LOCK_LOST);
                    }

                    $ran = $this->database->migrate();
                }

                // Every restore, after the migrations, before anything reads a secret (I-119).
                $this->stillLocked();
                $say('Re-encrypting the site’s secrets for this server’s APP_KEY.');
                $secrets = $this->reencrypt->execute(new ReencryptSecretsInput(OldKeys::fromAppKeyJson($point->appKey->json())))->lines();

                $this->stillLocked();
                $say('Recording the restore, and pausing backups until you say whether this server is now the site.');
                $restoreId = (string) $this->settle->execute(new SettleRestoredSiteInput(
                    $plan->runId,
                    $point->run->parts[BackupSnapshots::MANIFEST],
                    $plan->madeAt,
                    $began,
                    $plan->place,
                    $point->placeRepositoryId,
                    $safety,
                ));
                $this->endOpenUpdate();

                $this->stillLocked();
                $say($plan->mediaFiles === 1 ? "Restoring 1 media file from {$plan->place}." : 'Restoring '.number_format($plan->mediaFiles)." media files from {$plan->place}.");
                $this->media->restore($point->repository, $point->run->parts[BackupSnapshots::MEDIA], $point->mediaPairs, $plan->place);

                $say('Checking the media against the backup’s manifest.');
                $checked = $this->check->check($point->manifest, $point->mediaPairs);

                $say('Clearing the caches and restarting the queue workers.');
                // The restored database's own purge, and the CDN's (docs/architecture/page-cache.md).
                $this->purgePages();
                $this->database->emptyCache();
                $window->reassert();
                Artisan::call('queue:restart');

                if ($ran > 0) {
                    $this->fillInDerivations();
                }

                $say('Checking that the site answers.');
                $problem = $this->smoke->run($window->bypassSecret());

                if ($problem !== null) {
                    throw new RestoreStopped($problem);
                }

                $say($window->wasDownBefore() ? 'Leaving the site in maintenance, as it was before the restore.' : 'Opening the site again.');
                $window->reopen();
            });
        } catch (Throwable $error) {
            return $this->stopped($error, $stage, $window, $point, $safety, $missing, $restoreId, $removed, $began);
        } finally {
            $files->remove();
        }

        $forgotten = $this->forgottenPlaces($point);
        $this->end->execute(new EndRestoreInput($restoreId, $plan->runId, true, null, [
            ...$this->figures($point, $safety, $missing, $began),
            'migrations_run' => $ran,
            'tables_removed' => count($removed),
            'places_forgotten' => $forgotten,
            'variants_to_remake' => ImagesNeedingVariants::after(null, 1) !== [],
            'secrets_unreadable' => count($secrets),
            ...($checked['counts'] ?? ['originals_missing' => 0, 'originals_changed' => 0, 'variants_missing' => 0]),
        ]));

        return new RestoreResult(
            true,
            "Restored the backup of {$plan->made} from {$plan->place}: ".number_format($plan->tables).' tables, '.number_format($plan->rows).' rows and '
                .($plan->mediaFiles === 1 ? '1 media file.' : number_format($plan->mediaFiles).' media files.'),
            $restoreId,
            $safety,
            $this->notes($point, $safety, $missing, $forgotten, $secrets, $checked),
            ! $window->wasDownBefore(),
            $stage,
        );
    }

    /**
     * A restore that stopped: its words by how far it got, the site opened
     * again only when nothing changed, and the stop recorded
     * (docs/architecture/backups.md).
     *
     * @param  list<string>  $removed
     */
    private function stopped(Throwable $error, RestoreStage $stage, ?MaintenanceWindow $window, RestorePoint $point, ?string $safety, int $missing, ?string $restoreId, array $removed, CarbonImmutable $began): RestoreResult
    {
        $plan = $point->plan;

        // A stop Teme words itself: a step's, or another backup holding the lock (I-358).
        $worded = $error instanceof RestoreStopped || $error instanceof BackupAlreadyRunning;

        if (! $worded) {
            Log::error('Backups: the restore stopped on an error.', ['exception' => $error]);
        }

        $why = $worded ? $error->getMessage() : 'The restore stopped on an error Teme didn’t expect. The log has its details.';

        $sentence = match ($stage) {
            RestoreStage::SiteUntouched => "{$why} Nothing was changed: the site is as it was.",
            RestoreStage::TablesDropped => "{$why} The database wasn’t restored, but "
                .(count($removed) === 1 ? '1 table newer than the backup was' : count($removed).' tables newer than the backup were')
                .' removed first, so the site stays closed.'
                .($safety === null ? ' Run the restore again once the cause is fixed.' : " Restore the safety backup to put it back: php artisan teme:backup:restore {$safety}.".self::lacking($missing)),
            RestoreStage::DatabaseRestored => "{$why} The database is restored but the restore didn’t finish, so the site stays closed. Run php artisan teme:backup:restore {$plan->runId} again".($point->named ? ' with the same --kind and --key-file options' : '').' to finish it'
                .($safety === null ? '.' : ", or php artisan teme:backup:restore {$safety} to go back to the site as it was.".self::lacking($missing)),
        };

        try {
            // Opened again only when nothing changed; otherwise held shut, the flag put back if a cache emptied it.
            $stage === RestoreStage::SiteUntouched ? $window?->reopen() : $window?->reassert();
        } catch (Throwable $unopened) {
            Log::error('Backups: the restore couldn’t set maintenance mode after it stopped.', ['exception' => $unopened]);
        }

        $this->endQuietly($restoreId, $plan->runId, false, $sentence, $this->figures($point, $safety, $missing, $began));

        return new RestoreResult(false, $sentence, $restoreId, $safety, [], ! app()->maintenanceMode()->active(), $stage);
    }

    /**
     * The place the safety backup landed at, as the marker keeps it: never
     * its credentials (docs/architecture/backups.md).
     *
     * @return array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null
     */
    private function placeOf(?string $name): ?array
    {
        $place = $name === null ? null : BackupDestination::query()
            ->where('name', $name)
            ->where('state', '<>', BackupDestinationState::Removed->value)
            ->first();

        return $place === null ? null : ['name' => $place->name, 'kind' => $place->kind->value, 'location' => $place->location, 'repository_id' => $place->repository_id];
    }

    /**
     * What the way back can't bring, after a stop that names the safety
     * backup: the files it lacks, if any, with a space before.
     */
    private static function lacking(int $missing): string
    {
        return match (true) {
            $missing === 0 => '',
            $missing === 1 => ' '.__('That safety backup doesn’t hold 1 media file that was already missing from this server.'),
            default => ' '.__('That safety backup doesn’t hold :count media files that were already missing from this server.', ['count' => number_format($missing)]),
        };
    }

    /**
     * Why a safety backup that found the media gone as a whole stops the
     * restore: lost one by one, files can't come back; a volume that isn't
     * mounted can.
     */
    private static function unmounted(BackupResult $copy): string
    {
        if ($copy->absentMediaFolder !== null) {
            return __('The media folder :folder wasn’t on this server when the safety backup looked, which usually means a volume isn’t mounted. Check it, or add --without-safety-backup to restore anyway if the files really are gone.', ['folder' => $copy->absentMediaFolder]);
        }

        return $copy->missingFiles === 1
            ? __('The one media file the database names is missing from this server, which usually means the media folder isn’t there: a volume that isn’t mounted, say. Check it, or add --without-safety-backup to restore anyway if the file really is gone.')
            : __('Every media file the database names, all :count of them, is missing from this server, which usually means the media folder isn’t there: a volume that isn’t mounted, say. Check it, or add --without-safety-backup to restore anyway if the files really are gone.', ['count' => number_format($copy->missingFiles)]);
    }

    /**
     * @throws RestoreStopped
     */
    private function stillLocked(): void
    {
        if (! $this->lock->stillMine()) {
            throw new RestoreStopped(self::LOCK_LOST);
        }
    }

    private function purgePages(): void
    {
        if (PageCache::enabled()) {
            $this->pages->purge([PageTags::EVERYTHING]);
        }
    }

    /**
     * Derived document values for revisions a newer Teme derives more of, a
     * chunk at a time, as teme:content:rebuild-derivations does.
     */
    private function fillInDerivations(): void
    {
        $last = null;

        while (($ids = ContentIdsInOrder::after($last, self::CHUNK)) !== []) {
            $this->derivations->execute(new RebuildDocumentDerivationsInput($ids, true));
            $last = $ids[array_key_last($ids)];
        }
    }

    /**
     * The places this site knew before the restore that the restored database doesn't, by name.
     *
     * @return list<string>
     */
    private function forgottenPlaces(RestorePoint $point): array
    {
        $now = BackupDestination::query()->pluck('repository_id')->all();

        return array_values(array_map(
            fn (array $place): string => $place['name'],
            array_filter($point->placesBefore, fn (array $place): bool => ! in_array($place['repository_id'], $now, true)),
        ));
    }

    /**
     * @param  list<string>  $forgotten
     * @param  list<string>  $secrets  1.12b's report, a line a column
     * @param  array{originals_missing: list<string>, originals_changed: list<string>, variants_missing: list<string>, counts: array{originals_missing: int, originals_changed: int, variants_missing: int}}|null  $checked
     *
     * @return list<string>
     */
    private function notes(RestorePoint $point, ?string $safety, int $missing, array $forgotten, array $secrets, ?array $checked): array
    {
        $notes = [...$point->plan->notes, ...$secrets];

        if ($safety !== null && $point->plan->unfinished === null) {
            $notes[] = "The site as it was before the restore is kept as backup {$safety}: php artisan teme:backup:restore {$safety} brings it back.";

            if ($missing > 0) {
                $notes[] = $missing === 1
                    ? 'That safety backup lacks 1 media file the database names, because it wasn’t on this server, so going back to it can’t bring that file back.'
                    : 'That safety backup lacks '.number_format($missing).' media files the database names, because they weren’t on this server, so going back to it can’t bring them back.';
            }
        }

        if ($forgotten !== []) {
            $names = count($forgotten) === 1 ? $forgotten[0] : implode(', ', array_slice($forgotten, 0, -1)).' and '.end($forgotten);
            $notes[] = count($forgotten) === 1
                ? "{$names} was one of this site’s backup places before the restore and isn’t in the backup’s records. Its backups are still there, this restore’s safety backup among them."
                : "{$names} were among this site’s backup places before the restore and aren’t in the backup’s records. Their backups are still there, this restore’s safety backup among them.";
        }

        $notes = [...$notes, ...($checked === null ? [] : $this->mediaNotes($checked))];

        if (ImagesNeedingVariants::after(null, 1) !== []) {
            $notes[] = 'Some images were made with an older set of sizes than this Teme’s. php artisan teme:media:regenerate remakes them.';
        }

        return $notes;
    }

    /**
     * What the media check found, in words, singular or plural.
     *
     * @param  array{originals_missing: list<string>, originals_changed: list<string>, variants_missing: list<string>, counts: array{originals_missing: int, originals_changed: int, variants_missing: int}}  $checked
     *
     * @return list<string>
     */
    private function mediaNotes(array $checked): array
    {
        $words = [
            'originals_missing' => ['1 original the manifest names isn’t here', ' originals the manifest names aren’t here'],
            'originals_changed' => ['1 original differs from the manifest’s SHA-256', ' originals differ from the manifest’s SHA-256'],
            'variants_missing' => ['1 image size the database names isn’t here', ' image sizes the database names aren’t here'],
        ];
        $notes = [];

        foreach ($words as $kind => [$one, $many]) {
            $count = $checked['counts'][$kind];

            if ($count > 0) {
                $notes[] = ($count === 1 ? $one : number_format($count).$many).': '.implode(', ', $checked[$kind]).'.';
            }
        }

        return $notes;
    }

    /**
     * The restore run's figures; safety_missing_files counts the media files
     * the safety backup lacks, 0 when it lacked none or wasn't taken.
     *
     * @return array<string, mixed>
     */
    private function figures(RestorePoint $point, ?string $safety, int $missing, CarbonImmutable $began): array
    {
        $plan = $point->plan;

        return [
            'seconds' => (int) round($began->diffInSeconds(CarbonImmutable::now(), true)),
            'source_run' => $plan->runId,
            'made_at' => $plan->madeAt->utc()->toIso8601String(),
            'place' => $plan->place,
            'teme' => $plan->teme,
            'safety_run' => $safety,
            'safety_missing_files' => $missing,
            'tables' => $plan->tables,
            'rows' => $plan->rows,
            'media_files' => $plan->mediaFiles,
            'media_bytes' => $plan->mediaBytes,
        ];
    }

    /**
     * Records the end, and keeps quiet when even that fails: the log has the stop.
     *
     * @param  array<string, mixed>  $figures
     */
    private function endQuietly(?string $restoreId, string $runId, bool $succeeded, string $sentence, array $figures = []): void
    {
        try {
            $this->end->execute(new EndRestoreInput($restoreId, $runId, $succeeded, $sentence, $figures));
        } catch (Throwable $unrecorded) {
            Log::error('Backups: the restore’s end couldn’t be recorded.', ['exception' => $unrecorded]);
        }
    }

    /**
     * Ends an update the restored database holds open: a backup taken by a deploy, in the middle of an update,
     * brings that request back open, and nothing is still carrying it out. Nothing to end in a backup from before
     * the updater's table (I-3326).
     */
    private function endOpenUpdate(): void
    {
        if (! Schema::hasTable('teme_updates')) {
            return;
        }

        $open = $this->updates->open();

        if ($open !== null) {
            $this->endUpdate->execute(new EndTemeUpdateInput($open->id, UpdateStatus::Failed, 'A restore ended this update.'));
        }
    }

    /**
     * @throws AuthorizationException
     */
    private static function authorize(): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException(self::OPERATOR_ONLY);
        }
    }
}
