<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRefused;
use App\Domain\Backups\Verify\SpareDatabase;
use App\Domain\Backups\Verify\VerifyFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->spare = null;
    VerifyDatabase::unnamed();
});

afterEach(function (): void {
    $this->spare?->drop();
});

it('is named only when the server names a spare database (I-2406)', function (): void {
    expect(SpareDatabase::isNamed())->toBeFalse()
        ->and(fn () => SpareDatabase::open())->toThrow(BackupRefused::class, SpareDatabase::NOT_NAMED);

    $this->spare = VerifyDatabase::make();

    expect(SpareDatabase::isNamed())->toBeTrue();
});

it('refuses a database whose name doesn’t end in _verify, before it connects', function (): void {
    // Port 1: had it connected, it would have said it couldn't reach it instead.
    config(['database.connections.teme_verify' => [...DB::connection()->getConfig(), 'database' => 'teme_live', 'port' => 1]]);

    expect(fn () => SpareDatabase::open())->toThrow(
        BackupRefused::class,
        'Teme test-restores only into a database whose name ends in _verify and holds only letters, digits, dots, hyphens and underscores, and “teme_live” doesn’t.',
    );
});

it('knows one database by what the server says, whatever the connection is called', function (): void {
    $this->spare = VerifyDatabase::make();
    $config = config('database.connections.teme_verify');
    $one = DB::connectUsing('spare_one', [...$config, 'name' => 'spare_one'], force: true);
    $two = DB::connectUsing('spare_two', [...$config, 'name' => 'spare_two'], force: true);

    try {
        expect(SpareDatabase::sameDatabase($one, $two))->toBeTrue()
            ->and(SpareDatabase::sameDatabase($one, DB::connection()))->toBeFalse();
    } finally {
        DB::purge('spare_one');
        DB::purge('spare_two');
    }
});

it('says, never with the password, when it can’t reach the spare database', function (): void {
    $this->spare = VerifyDatabase::make();
    config(['database.connections.teme_verify.port' => 1, 'database.connections.teme_verify.password' => 'spare-secret-1234']);
    Log::spy();

    expect(fn () => SpareDatabase::open())->toThrow(BackupRefused::class, "Teme couldn’t reach the spare database “{$this->spare->name}”. The log has PostgreSQL’s own words.");

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $line): bool => str_starts_with($line, 'Backups: reaching the spare database: ') && ! str_contains($line, 'spare-secret-1234'))
        ->once();
});

it('empties the spare database: no table, no extension but plpgsql, and its public schema made again', function (): void {
    $this->spare = VerifyDatabase::make();
    $this->spare->run('CREATE EXTENSION pg_trgm');
    $this->spare->run('CREATE TABLE leftovers (id int)');
    $spare = SpareDatabase::open();

    $spare->empty();

    expect($this->spare->tables())->toBe([])
        ->and($this->spare->extensions())->toBe(['plpgsql'])
        ->and($this->spare->schemas())->toContain('public');

    $spare->close();
});

it('counts every table’s rows but the structure-only ones, as a backup’s moment does, and empties itself when closed', function (): void {
    $this->spare = VerifyDatabase::make();
    $this->spare->run('CREATE TABLE things (id int)');
    $this->spare->run('INSERT INTO things VALUES (1), (2)');
    $this->spare->run('CREATE TABLE cache (key text)');
    $this->spare->run("INSERT INTO cache VALUES ('a')");
    $this->spare->run('CREATE TABLE migrations (migration text)');
    $this->spare->run("INSERT INTO migrations VALUES ('2027_01_05_100000_a'), ('2027_01_11_100000_b')");
    $spare = SpareDatabase::open();

    expect($spare->tableCounts())->toBe(['migrations' => 2, 'things' => 2])
        ->and($spare->newestMigration())->toBe('2027_01_11_100000_b');

    $spare->close();

    expect($this->spare->tables())->toBe([]);
});

it('empties itself when closed on a fresh session when the one it opened can’t, and logs why (#216)', function (): void {
    Log::spy();
    $this->spare = VerifyDatabase::make();
    $this->spare->run('CREATE EXTENSION pg_trgm');
    $this->spare->run('CREATE TABLE things (title text)');
    $this->spare->run('CREATE INDEX things_title ON things USING gin (title gin_trgm_ops)');
    $spare = SpareDatabase::open();

    // The session it opened, left unable to run anything, as a stale view of the catalog leaves it.
    $session = DB::connection(SpareDatabase::SESSION);
    $session->beginTransaction();
    rescue(fn () => $session->statement('SELECT 1 / 0'), report: false);

    $spare->close();

    expect($this->spare->tables())->toBe([])
        ->and($this->spare->extensions())->toBe(['plpgsql']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Backups: emptying the spare database: '))->once();
});

it('names every difference between the restored rows and the manifest', function (): void {
    expect(SpareDatabase::differences(['media' => 3, 'users' => 1, 'gone' => 0], ['media' => 2, 'users' => 1, 'extra' => 1], 'b', 'a'))->toBe([
        'media has 2 rows in the restored copy, and 3 in the manifest.',
        'gone isn’t in the restored copy.',
        'extra is in the restored copy, but not in the manifest.',
        'The restored copy’s newest migration is a, and the manifest’s is b.',
    ])
        ->and(SpareDatabase::differences(['media' => 2], ['media' => 1], null, null))->toBe(['media has 1 row in the restored copy, and 2 in the manifest.'])
        ->and(SpareDatabase::differences(['users' => 1], ['users' => 1], 'b', 'b'))->toBe([]);
});

it('keeps its work files in a private folder of its own, and removes them by name', function (): void {
    $files = VerifyFiles::make();
    file_put_contents($files->path('teme.dump'), 'dump');
    file_put_contents($files->path('media-0'), 'file');
    $files->forget('media-0');

    expect(is_file($files->folder.DIRECTORY_SEPARATOR.'media-0'))->toBeFalse()
        ->and(is_file($files->path('teme.dump')))->toBeTrue()
        ->and(str_starts_with($files->folder, storage_path('app/verify/')))->toBeTrue()
        ->and(PHP_OS_FAMILY === 'Windows' || (fileperms($files->folder) & 0777) === 0700)->toBeTrue()
        ->and(fn () => $files->path('../escape'))->toThrow(InvalidArgumentException::class);

    $files->remove();

    expect(is_dir($files->folder))->toBeFalse();
});
