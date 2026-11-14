<?php

declare(strict_types=1);

use App\Domain\Backups\BackupTables;
use App\Domain\Backups\DatabaseMoment;
use App\Domain\Backups\Tools\PostgresProgram;
use App\Domain\Backups\Tools\PostgresTools;
use App\Domain\Backups\Tools\Restic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.backups.cache_path' => '']);
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    $this->media = new CommittedMedia;
    $this->made = [];
    $this->files = [];
});

afterEach(function (): void {
    $this->media->delete();

    foreach ($this->made as $made) {
        $made->delete();
    }

    foreach ($this->files as $file) {
        @unlink($file);
    }
});

it('exports a snapshot, and sees the database as it was when it opened, whatever commits after', function (): void {
    $before = $this->media->image();
    $moment = DatabaseMoment::open();

    try {
        $this->media->image();
        $seen = [];
        $moment->eachMedia(function (object $row) use (&$seen): void {
            $seen[] = $row->id;
        });

        expect($moment->snapshot)->toMatch('/^[0-9A-F]+-[0-9A-F]+-[0-9]+$/')
            ->and($seen)->toBe([$before])
            ->and($moment->tableCounts()['media'])->toBe(1);
    } finally {
        $moment->close();
    }

    expect(DB::table('media')->count())->toBe(2);
});

it('hands pg_dump the same moment: a row committed after it is in neither the manifest nor the dump', function (): void {
    $made = $this->made[] = TemporaryRepository::make();
    $postgres = app(PostgresTools::class);
    $before = $this->media->image();
    $moment = DatabaseMoment::open();

    try {
        $after = $this->media->image();
        $counted = $moment->tableCounts()['media'];
        $dumped = app(Restic::class)->run(
            $made->repository,
            ['backup', '--stdin-filename', 'teme.dump', '--stdin-from-command', '--', ...$postgres->dumpCommand($moment->snapshot)],
            environment: $postgres->environment(),
        );
    } finally {
        $moment->close();
    }

    $file = $this->files[] = TemporaryRepository::freshFolder().'.dump';
    app(Restic::class)->run($made->repository, ['dump', '--target', $file, (string) ($dumped->message('summary')['snapshot_id'] ?? ''), 'teme.dump']);
    $rows = $postgres->run(PostgresProgram::Restore, ['--data-only', '--table=media', '--file=-', $file]);

    expect($dumped->succeeded())->toBeTrue($dumped->detail())
        ->and($counted)->toBe(1)
        ->and($rows->successful())->toBeTrue($rows->errorOutput())
        ->and($rows->output())->toContain($before)
        ->and($rows->output())->not->toContain($after);
});

it('counts every table’s rows but the structure-only tables’, and reads the migrations and PostgreSQL’s version', function (): void {
    $moment = DatabaseMoment::open();

    try {
        $counts = $moment->tableCounts();
        $migrations = $moment->migrations();
        $version = $moment->serverVersion();
    } finally {
        $moment->close();
    }

    expect(array_keys($counts))->toContain('media', 'users', 'backup_runs', 'migrations', 'settings')
        ->and(array_intersect(array_keys($counts), BackupTables::STRUCTURE_ONLY))->toBe([])
        ->and(array_keys($counts))->toBe(collect(array_keys($counts))->sort()->values()->all())
        ->and($migrations)->toBe(['newest' => DB::table('migrations')->max('migration'), 'count' => DB::table('migrations')->count()])
        ->and($version)->toMatch('/^\d+(\.\d+)?$/')
        ->and((int) $version)->toBeGreaterThanOrEqual(18);
});

it('lets go of its session when it closes, even after a failed query', function (): void {
    $moment = DatabaseMoment::open();

    expect(array_key_exists(DatabaseMoment::CONNECTION, DB::getConnections()))->toBeTrue()
        ->and(fn () => DB::connection(DatabaseMoment::CONNECTION)->select('SELECT 1 / 0'))->toThrow(QueryException::class);

    $moment->close();
    $moment->close();

    expect(array_key_exists(DatabaseMoment::CONNECTION, DB::getConnections()))->toBeFalse();
});
