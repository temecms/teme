<?php

declare(strict_types=1);

use App\Domain\Backups\BackupTables;
use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\PostgresProgram;
use App\Domain\Backups\Tools\PostgresTools;
use App\Domain\Backups\Tools\Restic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.backups.cache_path' => '']);
    $this->made = [];
    $this->files = [];
    // A backup of this test's own database, read by restic from pg_dump.
    $this->backUp = function (array $environment, ?string $snapshot = null): array {
        $made = $this->made[] = TemporaryRepository::make();
        $postgres = app(PostgresTools::class);
        $result = app(Restic::class)->run(
            $made->repository,
            ['backup', '--stdin-filename', 'teme.dump', '--stdin-from-command', '--', ...$postgres->dumpCommand($snapshot)],
            environment: $environment,
        );

        return [$made, $result];
    };
});

afterEach(function (): void {
    foreach ($this->made as $made) {
        $made->delete();
    }

    foreach ($this->files as $file) {
        @unlink($file);
    }
});

it('points PostgreSQL\'s tools at a database through the environment, never the command line', function (): void {
    $tools = app(PostgresTools::class);
    $given = ['host' => 'db.example.internal', 'port' => 6543, 'database' => 'teme_verify', 'username' => 'teme_backup', 'password' => 'a-password-of-its-own', 'sslmode' => 'require'];

    expect($tools->environment($given))->toBe([
        'PGHOST' => 'db.example.internal',
        'PGPORT' => '6543',
        'PGDATABASE' => 'teme_verify',
        'PGUSER' => 'teme_backup',
        'PGPASSWORD' => 'a-password-of-its-own',
        'PGSSLMODE' => 'require',
        'PGAPPNAME' => 'teme-backup',
    ])
        ->and($tools->environment()['PGDATABASE'])->toBe(DB::connection()->getDatabaseName())
        ->and($tools->environment()['PGPORT'])->toBe((string) DB::connection()->getConfig('port'))
        ->and(array_filter($tools->dumpCommand(), fn (string $part): bool => preg_match('/^(-h|-p|-U|-d|-W|--host|--port|--username|--dbname|--password)/', $part) === 1))->toBe([]);
});

it('refuses a connection that names no host, or several', function (): void {
    $tools = app(PostgresTools::class);

    expect(fn () => $tools->environment(['host' => ['10.0.0.1', '10.0.0.2'], 'database' => 'teme']))
        ->toThrow(InvalidArgumentException::class, 'Teme backs up one database server: the connection must name one host.')
        ->and(fn () => $tools->environment(['host' => '', 'database' => 'teme']))
        ->toThrow(InvalidArgumentException::class, 'Teme backs up one database server: the connection must name one host.');
});

it('dumps in custom format with statistics, leaving out every structure-only table\'s rows (I-357)', function (): void {
    $tools = app(PostgresTools::class);
    $command = $tools->dumpCommand();

    expect($command[0])->toBe(app(BackupTools::class)->postgres(PostgresProgram::Dump))
        ->and(array_slice($command, 1, 3))->toBe(['--format=custom', '--statistics', '--no-password'])
        ->and(array_slice($command, 4))->toBe(array_map(fn (string $table): string => '--exclude-table-data='.$table, BackupTables::STRUCTURE_ONLY))
        ->and(array_slice($tools->dumpCommand('00000003-0000001B-1'), -1))->toBe(['--snapshot=00000003-0000001B-1'])
        ->and(fn () => $tools->dumpCommand('1; DROP TABLE users'))
        ->toThrow(InvalidArgumentException::class, '[1; DROP TABLE users] isn’t a snapshot PostgreSQL exported.');
});

it('backs the database up through restic, which runs pg_dump itself, and reads it back whole (I-2400)', function (): void {
    $postgres = app(PostgresTools::class);
    [$made, $backup] = ($this->backUp)($postgres->environment());
    $snapshot = (string) ($backup->message('summary')['snapshot_id'] ?? '');

    expect($backup->succeeded())->toBeTrue($backup->detail())
        ->and($snapshot)->toMatch('/^[0-9a-f]{64}$/');

    // No leading slash: restic on Windows can't find /teme.dump.
    $file = $this->files[] = TemporaryRepository::freshFolder().'.dump';
    $read = app(Restic::class)->run($made->repository, ['dump', '--target', $file, $snapshot, 'teme.dump']);
    $listed = $postgres->run(PostgresProgram::Restore, ['--list', $file]);
    $list = $listed->output();

    expect($read->succeeded())->toBeTrue($read->detail())
        ->and($listed->successful())->toBeTrue($listed->errorOutput())
        ->and($list)->toMatch('/ TABLE public backup_runs /')
        ->and($list)->toMatch('/ TABLE DATA public users /')
        ->and($list)->toMatch('/ TABLE public cache /')
        ->and($list)->not->toMatch('/ TABLE DATA public cache /')
        ->and($list)->not->toMatch('/ TABLE DATA public jobs /')
        ->and($list)->not->toMatch('/ TABLE DATA public sessions /')
        ->and($list)->toContain('STATISTICS DATA');
});

it('dumps the database as a snapshot another transaction exported sees it', function (): void {
    // This test's own transaction exports it, and stays open while pg_dump reads it, as a backup's will.
    $snapshot = (string) DB::scalar('SELECT pg_export_snapshot()');
    [, $backup] = ($this->backUp)(app(PostgresTools::class)->environment(), $snapshot);

    expect($snapshot)->toMatch('/^[0-9A-F]+-[0-9A-F]+-[0-9]+$/')
        ->and($backup->succeeded())->toBeTrue($backup->detail());
});

it('makes no backup when pg_dump fails, and says so', function (): void {
    /** @var array<string, mixed> $config */
    $config = DB::connection()->getConfig();
    [$made, $backup] = ($this->backUp)(app(PostgresTools::class)->environment([...$config, 'database' => 'teme_no_such_database']));

    expect($backup->succeeded())->toBeFalse()
        ->and($backup->exitCode)->toBe(1)
        ->and($backup->detail())->toContain('command failed')
        ->and(app(Restic::class)->run($made->repository, ['snapshots'])->json())->toBe([]);
});
