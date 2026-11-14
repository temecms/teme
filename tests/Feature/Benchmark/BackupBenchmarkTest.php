<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Benchmark\BackupBench\BackupBenchmark;
use App\Domain\Benchmark\BackupBench\BackupBenchOptions;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    // The media disks, the place and the key in a folder of the test's own, never the checkout's.
    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-backup-bench-'.Str::lower(Str::random(8));
    config([
        'filesystems.disks.media.root' => $this->folder.DIRECTORY_SEPARATOR.'media',
        'filesystems.disks.media-originals.root' => $this->folder.DIRECTORY_SEPARATOR.'media-originals',
    ]);
    Storage::forgetDisk(['media', 'media-originals']);
    [$this->site, $this->spare] = [null, null];
});

afterEach(function (): void {
    $this->spare?->drop();
    $this->site?->close();
    File::deleteDirectory($this->folder);
    Storage::forgetDisk(['media', 'media-originals']);
});

it('backs a synthetic site up, again after a night’s change through Back up now, reads it back, prunes, measures and test-restores it, every file real (I-367)', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->site = ScratchSite::open();
    $this->spare = VerifyDatabase::make();

    $result = app(BackupBenchmark::class)->run(new BackupBenchOptions(
        folder: $this->folder,
        items: 500,
        images: 100,
        scale: 0.01,
        night: 5,
        protectEvery: 100,
        comments: 0,
        redirects: 0,
        notFound: 0,
        aiActivity: 0,
    ));

    $backups = BackupRun::query()->whereNull('parent_id')->where('kind', BackupRunKind::Backup->value)->orderBy('started_at')->get();
    $admin = DB::table('users')->where('email', 'admin@bench.test')->value('id');
    $original = DB::table('media')->orderBy('id')->first(['original_path', 'sha256']);

    expect($result['failed'])->toBe([])
        ->and(array_column($result['runs'], 'name'))->toBe(['first', 'night', 'read-back', 'prune', 'test-restore'])
        ->and($result['files'])->toBe(600)
        ->and($result['night'])->toMatchArray(['images' => 5, 'items' => 50, 'files' => 30])
        ->and($result['protected'])->toBeGreaterThan(0)
        ->and($result['runs'][1]['figures']['media_files'])->toBe(630)
        ->and($result['runs'][1]['figures']['bytes_added'])->toBeLessThan($result['runs'][0]['figures']['bytes_added'])
        ->and($backups)->toHaveCount(2)
        ->and($backups[1]->started_by)->toBe(BackupTrigger::Person)
        ->and((string) $backups[1]->actor_id)->toBe((string) $admin)
        ->and($result['backUpNow'])->toBeLessThan(60.0)
        ->and($result['storage']['bytes'])->toBeGreaterThan(0)
        ->and(hash_file('sha256', $this->folder.DIRECTORY_SEPARATOR.'media-originals'.DIRECTORY_SEPARATOR.$original->original_path))->toBe($original->sha256)
        ->and(trim((string) file_get_contents($result['keyFile'])))->toMatch('/^[0-9A-Z]{4}(-[0-9A-Z]{4}){7}$/');
});

it('reports each run, each step and what the runs say, in the report’s words', function (): void {
    $run = fn (string $name, float $seconds, int $peak, array $figures = [], bool $succeeded = true, ?string $sentence = null, array $steps = []): array => [
        'name' => $name, 'succeeded' => $succeeded, 'sentence' => $sentence, 'problems' => [], 'seconds' => $seconds,
        'peakMemory' => $peak, 'firstAt' => 1.0, 'steps' => $steps, 'figures' => $figures,
    ];
    $result = [
        'items' => 200000, 'protected' => 200, 'images' => 40000, 'files' => 240000,
        'mediaBytes' => 7516192768, 'originalBytes' => 4026531840, 'databaseBytes' => 3221225472,
        'scale' => 0.25, 'seedSeconds' => 412.3, 'filesSeconds' => 96.0,
        'night' => ['images' => 40, 'items' => 400, 'files' => 240, 'bytes' => 7340032],
        'runs' => [
            $run('first', 612.0, 100663296, ['bytes_added' => 7516192768, 'database_bytes' => 1073741824], steps: [
                ['step' => 'Checking restic and PostgreSQL’s tools.', 'seconds' => 0.3],
                ['step' => 'Copying the database to Benchmark place.', 'seconds' => 250.2],
                ['step' => 'Backing up 240,000 media files to Benchmark place.', 'seconds' => 300.0],
            ]),
            $run('night', 88.2, 67108864, ['bytes_added' => 33554432]),
            $run('read-back', 40.0, 33554432),
            $run('prune', 55.5, 33554432),
            $run('test-restore', 301.0, 50331648, succeeded: false, sentence: 'The spare database answered 3 rows fewer in comments than the manifest.'),
        ],
        'backUpNow' => 0.4,
        'storage' => ['bytes' => 7516192768, 'sentence' => null, 'seconds' => 12.5],
        'keyFile' => '/tmp/key', 'place' => '/tmp/place', 'failed' => ['test-restore'],
    ];

    $report = app(BackupBenchmark::class)->report($result, [
        'label' => 'v0.1.0-rc.12', 'date' => '2026-11-01 02:00:00', 'postgres' => 'PostgreSQL 18.6',
        'restic' => '0.19.1', 'machine' => 'Linux vm', 'load' => '0.50 0.40 0.30',
    ]);

    expect($report)
        ->toContain('# Backup benchmark — v0.1.0-rc.12')
        ->toContain('- **Date:** 2026-11-01 02:00:00 · **PostgreSQL:** PostgreSQL 18.6 · **restic:** 0.19.1 · **Machine:** Linux vm · **Host load:** 0.50 0.40 0.30')
        ->toContain('- **The site:** 200,000 items, 200 of them password-protected; 40,000 images in 240,000 files, 7.0 GB; the database 3.0 GB. Seeded in 412.3 s; the images’ files written in 96.0 s.')
        ->toContain('- **The size:** every file is 25% of its full size, so a site of these images with every file at full size would hold about 28.0 GB, its originals about 15.0 GB (I-367). Counts of images, files and rows are full.')
        ->toContain('| The first backup | 612.0 | 96.0 MB | 7.0 GB | Done |')
        ->toContain('| The nightly backup, from Back up now, after 40 new images and 400 items saved again | 88.2 | 64.0 MB | 32.0 MB | Done |')
        ->toContain('| The nightly read-back of a seventh | 40.0 | 32.0 MB | — | Done |')
        ->toContain('| The weekly prune and check | 55.5 | 32.0 MB | — | Done |')
        ->toContain('| The weekly test restore | 301.0 | 48.0 MB | — | Failed: The spare database answered 3 rows fewer in comments than the manifest. |')
        ->toContain('Peak memory is Teme’s own PHP process; restic and pg_dump run beside it and aren’t counted.')
        ->toContain('### The first backup')
        ->toContain('| Backing up 240,000 media files to Benchmark place. | 300.0 |')
        ->toContain('- **Back up now** took 0.4 s from the request to its first step, without the scheduler’s minute: on a server the scheduler takes a waiting backup within a minute, once no backup, prune or check holds the backup lock (I-3172).')
        ->toContain('- **What the place holds:** 7.0 GB, counted by `restic stats --mode raw-data` in 12.5 s; Tools › Backups waits up to 60 s for it (I-2986).')
        ->toContain('- **The manifest in memory:** the first backup’s peak memory, 96.0 MB, holds every original’s SHA-256 (I-2481).')
        ->toContain('- **Spare disk:** a backup streams the database’s dump into restic and needs no room for it. The weekly test restore needs room for the dump, 1.0 GB, and a spare database the size of the site’s, about 3.0 GB. A restore needs room for the dump and the media, 7.0 GB.')
        ->toContain('- **At full size:** the first backup’s media step took 300.0 s for 7.0 GB; at full size, about 28.0 GB, it would take about 1,200.0 s at this machine’s rate. A place off the server adds the time its link takes to carry that much.');
});

it('refuses a database whose name doesn’t end in _bench, and leaves the default connection as it was', function (): void {
    $default = DB::getDefaultConnection();

    $code = Artisan::call('teme:bench:backup', ['--database' => 'teme_site', '--force' => true]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run: the benchmark only touches a database whose name ends in _bench, and this one is [teme_site].')
        ->and(DB::getDefaultConnection())->toBe($default);
});

it('asks before it writes into this checkout’s media folders, and changes nothing on no', function (): void {
    $default = DB::getDefaultConnection();

    $this->artisan('teme:bench:backup')
        ->expectsConfirmation('This writes the synthetic site’s images into this checkout’s media folders and rebuilds the benchmark’s database. Carry on?', 'no')
        ->expectsOutput('Nothing was changed.')
        ->assertExitCode(1);

    // Opening a media disk makes its folder, so a folder here means the command touched one.
    expect(DB::getDefaultConnection())->toBe($default)
        ->and(is_dir((string) config('filesystems.disks.media.root')))->toBeFalse()
        ->and(is_dir((string) config('filesystems.disks.media-originals.root')))->toBeFalse();
});

it('refuses a scale outside (0, 1] or fewer than one image, before it asks or connects', function (array $options): void {
    $default = DB::getDefaultConnection();

    $code = Artisan::call('teme:bench:backup', [...$options, '--database' => 'teme_site']);
    $output = Artisan::output();

    // teme_site would be refused too; these words show the sizes were refused first.
    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run: --scale must be above 0 and at most 1, and --images at least 1. Nothing was changed.')
        ->and($output)->not->toContain('_bench')
        ->and(DB::getDefaultConnection())->toBe($default)
        ->and(is_dir((string) config('filesystems.disks.media.root')))->toBeFalse();
})->with([
    'no scale' => [['--scale' => '0']],
    'a scale above 1' => [['--scale' => '1.5']],
    'no images' => [['--images' => '0']],
]);
