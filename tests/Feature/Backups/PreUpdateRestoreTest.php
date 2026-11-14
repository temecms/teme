<?php

declare(strict_types=1);

use App\Domain\Backups\DatabaseMoment;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\MediaInventory;
use App\Domain\Backups\Restore\RestoreCompatibility;
use App\Domain\Deployment\DeployHistory;
use App\Domain\Deployment\DeployOutcome;
use App\Domain\Deployment\DeployReport;
use App\Domain\Themes\ThemeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\ScratchSite;

uses(RefreshDatabase::class);

/** A format-1 manifest that this server can restore, with $overrides laid over it. */
function pudManifest(array $overrides = []): Manifest
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

/** The manifest a backup makes now. */
function pudTake(): Manifest
{
    $moment = DatabaseMoment::open();

    try {
        $inventory = MediaInventory::take($moment, MediaInventory::roots());

        return Manifest::make('01jbackuprun0000000000000a', CarbonImmutable::parse('2026-11-10 02:00:03', 'UTC'), $moment, $inventory);
    } finally {
        $moment->close();
    }
}

// A backup's moment sees only committed rows, so these run on a database of their own.
beforeEach(function (): void {
    $this->site = ScratchSite::open();
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    config(['teme.version' => '0.1.0-rc.14', 'teme.tagged' => true]);
});

afterEach(function (): void {
    $this->site->close();
});

it('a backup records the release its database was last deployed by', function (): void {
    updDeployed('0.1.0-rc.14');
    config(['teme.version' => '0.1.0-rc.15']);

    $data = pudTake()->toArray();

    expect($data['schema'])->toBe(['teme' => '0.1.0-rc.14', 'tagged' => true])
        ->and($data['teme'])->toBe('0.1.0-rc.15')
        ->and(array_keys($data))->toContain('schema');
});

it('records no schema before any deploy is recorded, and none on a database without the tagged column', function (): void {
    expect(pudTake()->toArray()['schema'])->toBeNull();

    updDeployed('0.1.0-rc.14');
    Schema::dropColumns('deploy_runs', ['tagged']);

    expect(pudTake()->toArray()['schema'])->toBeNull();
});

it('records a deploy’s taggedness, and records a deploy still on a database without the column', function (): void {
    updOperator();
    $record = fn () => app(DeployHistory::class)->record(DeployReport::forDeploy()->end(DeployOutcome::Deployed, 'Deployed.'), CarbonImmutable::now());

    $record();
    config(['teme.tagged' => false]);
    $record();

    expect(DB::table('deploy_runs')->orderBy('finished_at')->pluck('tagged')->all())->toBe([true, false]);

    Schema::dropColumns('deploy_runs', ['tagged']);
    DB::table('deploy_runs')->delete();
    $record();

    expect(DB::table('deploy_runs')->count())->toBe(1);
});

it('fills the rows already there: tagged unless their version is 0.1.0', function (): void {
    Schema::dropColumns('deploy_runs', ['tagged']);

    foreach (['0.1.0', '0.1.0-rc.14'] as $version) {
        DB::table('deploy_runs')->insert([
            'id' => strtolower((string) Str::ulid()), 'version' => $version, 'revision' => null, 'outcome' => 'deployed', 'exit_code' => 0,
            'sentence' => 'Deployed.', 'backup_run_id' => null, 'report' => '{}', 'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    (require database_path('migrations/2027_03_02_100000_add_tagged_to_deploy_runs.php'))->up();

    expect(DB::table('deploy_runs')->orderBy('version')->pluck('tagged', 'version')->all())->toBe(['0.1.0' => false, '0.1.0-rc.14' => true]);
});

it('restores a backup taken by the new release’s deploy onto the release it came from', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/trail')]]);
    $here = app(ThemeRegistry::class)->find('trail')->manifest->version();
    $backup = pudManifest([
        'teme' => '0.1.0-rc.15', 'tagged' => true, 'schema' => ['teme' => '0.1.0-rc.14', 'tagged' => true],
        'packages' => ['acme/teme-extras' => '1.2.0'],
        'theme' => ['code' => 'trail', 'version' => '99.0.0'],
    ]);

    expect(RestoreCompatibility::check($backup))->toBe(['problems' => [], 'notes' => [
        "This backup was taken by Teme 0.1.0-rc.15, whose theme trail is 99.0.0; this server has {$here}. The database is Teme 0.1.0-rc.14’s, which runs with it.",
        'This backup was taken by Teme 0.1.0-rc.15 while its database was Teme 0.1.0-rc.14’s. These packages differ from the backup’s site: acme/teme-extras 1.2.0 (here: missing).',
    ]]);
});

it('still refuses it onto an older release, by the schema’s version and by its migrations', function (): void {
    config(['teme.version' => '0.1.0-rc.13']);
    $schema = ['teme' => '0.1.0-rc.14', 'tagged' => true];

    expect(RestoreCompatibility::check(pudManifest(['teme' => '0.1.0-rc.15', 'tagged' => true, 'schema' => $schema]))['problems'])
        ->toBe(['This backup was made by Teme 0.1.0-rc.14, newer than this server’s Teme 0.1.0-rc.13. Deploy Teme 0.1.0-rc.14 or later here first.'])
        ->and(RestoreCompatibility::check(pudManifest([
            'teme' => '0.1.0-rc.15', 'tagged' => true, 'schema' => ['teme' => '0.1.0-rc.13', 'tagged' => true],
            'database' => ['newest_migration' => '2099_01_01_000000_from_the_future'],
        ]))['problems'])
        ->toBe(['This backup’s database has migrations this Teme doesn’t know, up to 2099_01_01_000000_from_the_future. Deploy the Teme that made it, or a later one, first.']);
});

it('still refuses a pre-update backup whose theme isn’t installed here', function (): void {
    $backup = pudManifest([
        'teme' => '0.1.0-rc.15', 'tagged' => true, 'schema' => ['teme' => '0.1.0-rc.14', 'tagged' => true],
        'theme' => ['code' => 'atlas', 'version' => '2.3.0'],
    ]);

    expect(RestoreCompatibility::check($backup)['problems'])->toBe(['The backup’s theme, atlas 2.3.0, isn’t installed here. Deploy it first.']);
});

it('never reads an old untagged 0.1.0 backup as the real v0.1.0', function (): void {
    config(['teme.version' => '0.1.0', 'teme.tagged' => true]);
    $old = Manifest::fromJson(json_encode(array_diff_key(
        pudManifest(['teme' => '0.1.0', 'packages' => ['acme/teme-extras' => '1.2.0']])->toArray(),
        ['tagged' => true],
    ), JSON_THROW_ON_ERROR));

    expect(RestoreCompatibility::check($old))->toBe(['problems' => [], 'notes' => [
        'This backup was made by Teme 0.1.0 and this server runs 0.1.0, which Teme can’t compare. These packages differ from the backup’s site: acme/teme-extras 1.2.0 (here: missing).',
    ]]);
});

it('reads every manifest written before the schema key as before', function (): void {
    $framework = Manifest::packages()['laravel/framework'];
    $packages = ['teme' => '0.1.0-rc.14', 'tagged' => true, 'packages' => ['laravel/framework' => '99.0.0']];

    expect(RestoreCompatibility::check(pudManifest($packages)))->toBe(['problems' => [
        "This server runs the backup’s Teme 0.1.0-rc.14 without the packages its site ran with: laravel/framework 99.0.0 (here: {$framework}). Deploy the same packages first.",
    ], 'notes' => []]);
});
