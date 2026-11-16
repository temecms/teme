<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Restore\BackupAppKey;
use App\Domain\Backups\Restore\MediaCheck;
use App\Domain\Backups\Restore\MediaRestore;
use App\Domain\Backups\Restore\RestoreCompatibility;
use App\Domain\Backups\Restore\RestorePoint;
use App\Domain\Backups\Restore\RestorePoints;
use App\Domain\Backups\Restore\RestoreRefused;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Restore\RestoreRoom;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\RunnableBackups;

uses(RefreshDatabase::class);

/** A format-1 manifest that this server can restore, with $overrides laid over it. */
function rpManifest(array $overrides = []): Manifest
{
    $folders = ['originals' => '/srv/teme/storage/app/private/media-originals', 'public' => '/srv/teme/storage/app/public/media'];

    return Manifest::fromJson(json_encode(array_replace_recursive([
        'format' => 1,
        'run' => Str::lower((string) Str::ulid()),
        'made_at' => '2026-10-18T02:00:00.000000+00:00',
        'teme' => (string) config('teme.version'),
        'database' => [
            'file' => 'teme.dump', 'postgres' => '18.0', 'postgres_major' => 18,
            'newest_migration' => RestoreCompatibility::newestMigration(), 'migrations' => 1,
            'tables' => ['users' => 1], 'structure_only' => [],
        ],
        'media' => [
            'items' => 0, 'files' => 0, 'bytes' => 0, 'variants' => ['files' => 0, 'bytes' => 0],
            'roots' => $folders, 'snapshot_roots' => $folders, 'originals' => [], 'missing' => ['count' => 0, 'files' => []],
        ],
        'theme' => null,
        'packages' => Manifest::packages(),
        'app_key' => 'app-key.json',
    ], $overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** A folder as MediaRestore writes it: through any link, forward slashes, no slash at its end. */
function rpPath(string $folder): string
{
    $real = realpath($folder);

    return rtrim(str_replace('\\', '/', $real === false ? $folder : $real), '/');
}

/** The preflight, as the restore runs it. */
function rpFind(RestoreRequest $request = new RestoreRequest, ?array $unfinished = null): RestorePoint
{
    return PlaceRepositories::during(fn (PlaceRepositories $open): RestorePoint => app(RestorePoints::class)->find($request, $open, $unfinished));
}

/** The problems a refusal names, or [] when there was none. */
function rpRefusal(Closure $preflight): array
{
    try {
        $preflight();
    } catch (RestoreRefused $refused) {
        return $refused->problems;
    }

    return [];
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->key = 'base64:'.base64_encode(str_repeat('k', 32));
    config(['app.key' => $this->key, 'app.previous_keys' => []]);
    $this->backups = null;
});

afterEach(function (): void {
    $this->backups?->delete();
});

it('plans the newest complete backup at the first place that answers', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);
    $newest = app(BackupSite::class)->run(BackupTrigger::Command);

    $point = rpFind();

    expect($point->plan->runId)->toBe($newest->runId)
        ->and($point->plan->place)->toBe('Place 1')
        ->and($point->plan->teme)->toBe((string) config('teme.version'))
        ->and($point->plan->tables)->toBe(count($point->manifest->tables()))
        ->and($point->plan->safetyBackup)->toBeFalse()
        ->and($point->plan->notes)->toBe([])
        ->and($point->run->complete())->toBeTrue()
        ->and($point->mediaPairs)->toHaveCount(2)
        ->and($point->appKey->keys())->toBe([$this->key])
        ->and($point->placesBefore)->toBe([['name' => 'Place 1', 'repository_id' => $this->backups->places[0]->repository_id]]);
});

it('plans the backup named, at the place named, a paused place too', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    $first = app(BackupSite::class)->run(BackupTrigger::Command);
    app(BackupSite::class)->run(BackupTrigger::Command);
    BackupDestination::query()->update(['paused_at' => now()]);

    $point = rpFind(new RestoreRequest(place: 'place 2', runId: $first->runId));

    expect($point->plan->runId)->toBe($first->runId)
        ->and($point->plan->place)->toBe('Place 2');
});

it('refuses, in words, no place, a place it doesn’t know and a run the place doesn’t hold', function (): void {
    expect(rpRefusal(fn () => rpFind()))->toBe(['There’s no place backups are kept, so there’s nothing to restore from. On a new server, name the place with --kind.']);

    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);

    expect(rpRefusal(fn () => rpFind(new RestoreRequest(place: 'Nowhere'))))->toBe(['There’s no place called “Nowhere”.'])
        ->and(rpRefusal(fn () => rpFind(new RestoreRequest(place: 'Place 1', runId: 'nope'))))
        ->toBe(['Place 1 has no complete backup nope. php artisan teme:backup:list shows the ones it has.']);
});

it('says why for each place when none answers with a backup', function (): void {
    $this->backups = RunnableBackups::atFolders(2);
    app(BackupSite::class)->run(BackupTrigger::Command);
    $this->backups->repositories[0]->delete();
    $this->backups->repositories[1]->delete();

    expect(rpRefusal(fn () => rpFind()))->toBe([
        'No place answered with a backup to restore. Teme found no backups at Place 1. Check its address, and that the storage is still there. Teme found no backups at Place 2. Check its address, and that the storage is still there.',
    ]);
});

it('accepts a backup of this server’s own Teme, migrations, PostgreSQL and packages', function (): void {
    expect(RestoreCompatibility::check(rpManifest()))->toBe(['problems' => [], 'notes' => []]);
});

it('refuses a newer Teme, a migration this code lacks and a newer PostgreSQL, naming each at once', function (): void {
    config(['teme.tagged' => true]);
    $checked = RestoreCompatibility::check(rpManifest([
        'teme' => '99.0.0',
        'tagged' => true,
        'database' => ['newest_migration' => '2099_01_01_000000_from_the_future', 'postgres' => '99.1', 'postgres_major' => 99],
    ]));
    $here = (string) config('teme.version');
    $server = strtok((string) DB::selectOne('SHOW server_version')->server_version, ' ');

    expect($checked['problems'])->toBe([
        "This backup was made by Teme 99.0.0, newer than this server’s Teme {$here}. Deploy Teme 99.0.0 or later here first.",
        'This backup’s database has migrations this Teme doesn’t know, up to 2099_01_01_000000_from_the_future. Deploy the Teme that made it, or a later one, first.',
        "This backup comes from PostgreSQL 99.1, newer than this server’s PostgreSQL {$server}. Restore it onto PostgreSQL 99.1 or later.",
    ]);
});

it('refuses a theme missing here or older here, naming it', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/trail')]]);
    $trail = app(ThemeRegistry::class)->find('trail');

    expect(RestoreCompatibility::check(rpManifest(['theme' => ['code' => 'atlas', 'version' => '2.3.0']]))['problems'])
        ->toBe(['The backup’s theme, atlas 2.3.0, isn’t installed here. Deploy it first.'])
        ->and(RestoreCompatibility::check(rpManifest(['theme' => ['code' => 'trail', 'version' => '99.0.0']]))['problems'])
        ->toBe(["The backup’s theme, trail, is {$trail->manifest->version()} here, older than the backup’s 99.0.0. Deploy 99.0.0 or later first."])
        ->and(RestoreCompatibility::check(rpManifest(['theme' => ['code' => 'trail', 'version' => '0.0.1']])))->toBe(['problems' => [], 'notes' => []]);
});

it('refuses packages missing or older under the same Teme, and only notes them under a newer one', function (): void {
    config(['teme.tagged' => true]);
    $framework = Manifest::packages()['laravel/framework'];
    $packages = ['packages' => ['acme/teme-extras' => '1.2.0', 'laravel/framework' => '99.0.0'], 'tagged' => true];
    // In the manifest's order: the backup's own packages by name, then any it had beyond them.
    $list = "laravel/framework 99.0.0 (here: {$framework}), acme/teme-extras 1.2.0 (here: missing)";
    $here = (string) config('teme.version');

    expect(RestoreCompatibility::check(rpManifest($packages)))->toBe(['problems' => [
        "This server runs the backup’s Teme {$here} without the packages its site ran with: {$list}. Deploy the same packages first.",
    ], 'notes' => []]);

    config(['teme.version' => '99.0.0']);

    expect(RestoreCompatibility::check(rpManifest([...$packages, 'teme' => $here])))->toBe(['problems' => [], 'notes' => [
        "This server’s Teme 99.0.0 is newer than the backup’s {$here}. These packages differ from the backup’s site: {$list}.",
    ]]);
});

it('restores a backup made before Teme knew its version onto a tagged release, still guarding the schema (I-?? draft)', function (): void {
    config(['teme.version' => '0.1.0-rc.14', 'teme.tagged' => true]);
    $before = ['teme' => '0.1.0', 'packages' => ['acme/teme-extras' => '1.2.0']];
    $old = Manifest::fromJson(json_encode(array_diff_key(rpManifest($before)->toArray(), ['tagged' => true]), JSON_THROW_ON_ERROR));
    $framework = Manifest::packages()['laravel/framework'];

    expect(RestoreCompatibility::check($old))->toBe(['problems' => [], 'notes' => [
        'This backup was made by Teme 0.1.0 and this server runs 0.1.0-rc.14, which Teme can’t compare. These packages differ from the backup’s site: acme/teme-extras 1.2.0 (here: missing).',
    ]])
        // An untagged build on either side skips the comparison too.
        ->and(RestoreCompatibility::check(rpManifest(['teme' => '99.0.0', 'tagged' => false]))['problems'])->toBe([])
        // Two releases still compare, and the newest-migration check still refuses.
        ->and(RestoreCompatibility::check(rpManifest(['teme' => '0.1.0-rc.15', 'tagged' => true]))['problems'])
        ->toBe(['This backup was made by Teme 0.1.0-rc.15, newer than this server’s Teme 0.1.0-rc.14. Deploy Teme 0.1.0-rc.15 or later here first.'])
        ->and(RestoreCompatibility::check(rpManifest(['teme' => '0.1.0', 'database' => ['newest_migration' => '2099_01_01_000000_from_the_future']]))['problems'])
        ->toBe(['This backup’s database has migrations this Teme doesn’t know, up to 2099_01_01_000000_from_the_future. Deploy the Teme that made it, or a later one, first.']);
});

it('refuses media folders that aren’t folders here, hold more than media, or are laid out differently', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $pairs = MediaRestore::pairs(rpManifest());

    expect($pairs['problems'])->toBe([])
        ->and(array_column($pairs['pairs'], 'role'))->toBe(['originals', 'public'])
        ->and($pairs['pairs'][1]['from'])->toBe('/srv/teme/storage/app/public/media')
        ->and($pairs['pairs'][1]['to'])->toBe(rpPath(Storage::disk('media')->path('')));

    $shared = '/srv/teme/storage/app/media';
    expect(MediaRestore::pairs(rpManifest(['media' => ['snapshot_roots' => ['originals' => $shared, 'public' => $shared]]]))['problems'])
        ->toBe(['The two media disks share one folder here and had two on the backup’s server, or the other way round. Lay them out as the backup’s server did.']);

    config(['filesystems.disks.media' => ['driver' => 'local', 'root' => storage_path('app/public'), 'throw' => true]]);
    Storage::forgetDisk('media');
    expect(MediaRestore::pairs(rpManifest())['problems'])
        ->toBe(['The media disk “media” is kept in '.rpPath(storage_path('app/public')).', which holds more than media: restoring it would delete the other files there. Give the media a folder of its own.']);

    // A disk on S3, as tests/Feature/Backups/ManifestTest.php stands one in.
    Storage::set('media', Mockery::mock(Filesystem::class));
    expect(MediaRestore::pairs(rpManifest())['problems'])->toBe(['The media disk “media” isn’t a folder on this server, so Teme can’t restore its files.']);
});

it('refuses a media folder restic’s --delete could empty wrongly: no full path, a filesystem’s root, or among the site’s code', function (): void {
    $code = rpPath(base_path()).'/resources/media';
    // '/' resolves to the drive's root on Windows ('C:/'), and a root keeps its slash.
    $filesystemRoot = rpPath('/').'/';

    expect(MediaRestore::problemWith('media', '/'))
        ->toBe("The media disk “media” is kept in {$filesystemRoot}, which holds more than media: restoring it would delete the other files there. Give the media a folder of its own.")
        ->and(MediaRestore::problemWith('media', ''))->toBe('The media disk “media” has no full path to a folder on this server. Give it one.')
        ->and(MediaRestore::problemWith('media', 'relative/media/'))->toBe('The media disk “media” has no full path to a folder on this server. Give it one.')
        ->and(MediaRestore::problemWith('media', $code.'/'))
        ->toBe("The media disk “media” is kept in {$code}, among the site’s code, where a restore mustn’t delete. Keep the media under ".storage_path().' or outside the site.')
        ->and(MediaRestore::problemWith('media', storage_path('app/public/media').DIRECTORY_SEPARATOR))->toBeNull()
        ->and(MediaRestore::problemWith('media', sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-media'))->toBeNull();
});

it('says when there isn’t room, in sizes people read', function (): void {
    expect(RestoreRoom::problem(3 * 1024 ** 3, 1024 ** 3, '/srv/teme/storage/app/restore', 'database'))
        ->toBe('There isn’t room for the copy of the database: it needs 3.0 GB in /srv/teme/storage/app/restore, and 1.0 GB is free.')
        ->and(RestoreRoom::problem(1536, 512, '/srv/media', 'media'))
        ->toBe('There isn’t room for the media: it needs about 1.5 KB more in /srv/media, and 512 B is free.')
        ->and(RestoreRoom::problem(10, 10, '/srv/media', 'media'))->toBeNull()
        ->and(RestoreRoom::problem(10, false, '/srv/media', 'media'))->toBeNull()
        ->and(array_map(RestoreRoom::size(...), [0, 512, 1000, 1536, 1024 ** 3]))->toBe(['0 B', '512 B', '1.0 KB', '1.5 KB', '1.0 GB']);
});

it('reads the backup’s APP_KEY, hides it, and says whether this server holds it', function (): void {
    $old = 'base64:'.base64_encode(str_repeat('o', 32));
    $key = BackupAppKey::fromArray(['key' => $this->key, 'previous_keys' => [$old]]);

    expect($key->keys())->toBe([$this->key, $old])
        ->and(print_r($key, true))->not->toContain($this->key)
        ->and(fn () => serialize($key))->toThrow(LogicException::class)
        ->and($key->opensHere())->toBeFalse()
        ->and(fn () => BackupAppKey::fromArray(['key' => '']))->toThrow(InvalidArgumentException::class);

    config(['app.previous_keys' => [$old]]);

    expect($key->opensHere())->toBeTrue();
});

it('plans a backup made under another APP_KEY: Teme re-encrypts its secrets for this server', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);

    expect(rpRefusal(fn () => rpFind()))->toBe([]);

    config(['app.previous_keys' => [$this->key]]);

    expect(rpRefusal(fn () => rpFind()))->toBe([]);
});

it('plans a safety backup for a site with data, and refuses one it can’t keep unless told to', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);
    User::factory()->create();

    expect(RestorePoints::siteHasData())->toBeTrue()
        ->and(rpFind()->plan->safetyBackup)->toBeTrue()
        ->and(rpFind(unfinished: ['run' => 'x', 'safety' => null, 'safety_place' => null, 'since' => '2026-10-18T02:00:00+00:00'])->plan->safetyBackup)->toBeFalse();

    BackupDestination::query()->update(['paused_at' => now()]);

    expect(rpRefusal(fn () => rpFind()))->toBe(['This site has data, and its backups are paused since an earlier restore, so Teme can’t back it up before replacing it. Answer that restore first with php artisan teme:backup:restored, or add --without-safety-backup.'])
        ->and(rpFind(new RestoreRequest(withoutSafetyBackup: true))->plan->safetyBackup)->toBeFalse()
        ->and(app(BackupPlaces::class)->isPaused())->toBeTrue();
});

it('refuses a database that hasn’t run this Teme’s migrations, or lacks the maintenance flag’s table', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    app(BackupSite::class)->run(BackupTrigger::Command);
    DB::table('migrations')->where('migration', RestoreCompatibility::newestMigration())->delete();

    expect(RestoreCompatibility::pendingMigrations())->toBe(1)
        ->and(rpRefusal(fn () => rpFind()))->toBe(['This server’s database hasn’t run this Teme’s migrations. Run php artisan migrate first.']);

    DB::table('migrations')->insert(['migration' => RestoreCompatibility::newestMigration(), 'batch' => 99]);
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database', 'cache.stores.database.table' => 'cache_elsewhere']);

    expect(rpRefusal(fn () => rpFind()))->toBe(['Maintenance mode keeps its flag in the cache_elsewhere table, which this database doesn’t have. Run php artisan migrate first.'])
        ->and(Schema::hasTable('cache'))->toBeTrue();
});

it('restores only the roles a backup holds, and says in one line where the others are (I-3456)', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['filesystems.disks.media-originals-bucket.bucket' => 'teme-originals']);

    $mixed = MediaRestore::pairs(rpManifest(['media' => ['locations' => ['public' => 'server', 'originals' => 'bucket'], 'snapshot_roots' => ['originals' => null]]]));

    expect($mixed['problems'])->toBe([])
        ->and(array_column($mixed['pairs'], 'role'))->toBe(['public'])
        ->and($mixed['notes'])->toBe(['The image originals were in object storage when this backup was made, so they aren’t in it; the restored database expects them in the bucket “teme-originals”.']);

    $both = MediaRestore::pairs(rpManifest(['media' => ['locations' => ['public' => 'bucket', 'originals' => 'bucket']]]));

    expect($both['problems'])->toBe([])
        ->and($both['pairs'])->toBe([])
        ->and($both['notes'])->toHaveCount(2);

    // A manifest from before media could be in a bucket: both roles on the server, as before.
    $old = MediaRestore::pairs(rpManifest());

    expect(array_column($old['pairs'], 'role'))->toBe(['originals', 'public'])
        ->and($old['notes'])->toBe([]);
});

it('checks after a restore only the roles it restored, never one the backup left in a bucket', function (): void {
    $manifest = rpManifest(['media' => [
        'locations' => ['public' => 'bucket', 'originals' => 'bucket'],
        'originals' => [['id' => '01JA0000000000000000000000', 'disk' => 'originals', 'path' => '01JA0000000000000000000000/v1/a.jpg', 'bytes' => 1, 'sha256' => str_repeat('0', 64)]],
    ]]);

    expect(app(MediaCheck::class)->check($manifest, [])['counts'])->toBe(['originals_missing' => 0, 'originals_changed' => 0, 'variants_missing' => 0]);
});
