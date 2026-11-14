<?php

declare(strict_types=1);

use App\Domain\Backups\Tools\BackupToolMissing;
use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\PostgresProgram;
use App\Domain\Backups\Tools\ToolCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds restic and PostgreSQL\'s two tools where this computer is configured to, at versions Teme can use (I-2400)', function (): void {
    $report = app(BackupTools::class)->check();
    [$restic, $dump, $restore] = $report->tools;

    expect($report->problems)->toBe([])
        ->and($report->ready())->toBeTrue()
        ->and($restic->path)->toBeFile()
        ->and(version_compare((string) $restic->version, BackupTools::RESTIC_VERSION, '>='))->toBeTrue()
        ->and($dump->major())->toBeGreaterThanOrEqual(BackupTools::POSTGRES_MAJOR)
        ->and($restore->version)->toBe($dump->version)
        ->and((int) $report->serverVersion)->toBeGreaterThanOrEqual(BackupTools::POSTGRES_MAJOR);
});

it('finds PostgreSQL\'s two tools in one folder', function (): void {
    $tools = app(BackupTools::class);
    $dump = $tools->postgres(PostgresProgram::Dump);
    $restore = $tools->postgres(PostgresProgram::Restore);

    expect(dirname($dump))->toBe(dirname($restore))
        ->and(basename($dump))->toBe(PostgresProgram::Dump->fileName())
        ->and(basename($restore))->toBe(PostgresProgram::Restore->fileName());

    // A folder written with its last separator finds the same file.
    config(['teme.backups.postgres_bindir' => dirname($dump).DIRECTORY_SEPARATOR]);

    expect($tools->postgres(PostgresProgram::Dump))->toBe($dump);
});

it('says what to install or set when a program isn\'t where the configuration says', function (): void {
    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-no-such-folder';
    $nowhere = $folder.DIRECTORY_SEPARATOR.'restic';
    config(['teme.backups.restic' => $nowhere]);

    expect(fn () => app(BackupTools::class)->restic())
        ->toThrow(BackupToolMissing::class, "There’s no program at {$nowhere}, where TEME_RESTIC_BINARY says restic is. Install restic 0.19.1 there, or correct the path.");

    config(['teme.backups.restic' => 'teme-no-such-program', 'teme.backups.postgres_bindir' => $folder]);
    $report = app(BackupTools::class)->check();

    expect(fn () => app(BackupTools::class)->restic())
        ->toThrow(BackupToolMissing::class, 'restic isn’t on the PATH. Install restic 0.19.1, or set TEME_RESTIC_BINARY to where it is.')
        ->and($report->ready())->toBeFalse()
        ->and(array_map(fn (ToolCheck $tool): ?string => $tool->path, $report->tools))->toBe([null, null, null])
        ->and($report->problems)->toBe([
            'restic isn’t on the PATH. Install restic 0.19.1, or set TEME_RESTIC_BINARY to where it is.',
            "There’s no pg_dump in {$folder}, where TEME_POSTGRES_BINDIR says PostgreSQL’s tools are. Install PostgreSQL 18’s command-line tools there, or correct the folder.",
            "There’s no pg_restore in {$folder}, where TEME_POSTGRES_BINDIR says PostgreSQL’s tools are. Install PostgreSQL 18’s command-line tools there, or correct the folder.",
        ]);
});

it('says in words what makes a program unusable, and nothing when all three will do (I-366)', function (): void {
    $tool = fn (string $name, ?string $version, string $looked = '', ?string $path = '/opt/bin/tool'): ToolCheck => new ToolCheck($name, $looked, $path, $version);
    $restic = $tool('restic', '0.19.1', 'restic');
    $dump = $tool('pg_dump', '18.6');
    $restore = $tool('pg_restore', '18.6');

    expect(BackupTools::problems($restic, $dump, $restore, '18.4'))->toBe([])
        ->and(BackupTools::problems($tool('restic', '0.20.0', 'restic'), $tool('pg_dump', '19.1'), $tool('pg_restore', '19.1'), '18.4'))->toBe([])
        ->and(BackupTools::problems($tool('restic', null, '/opt/restic', null), $dump, $restore, '18.4'))
        ->toBe(['There’s no program at /opt/restic, where TEME_RESTIC_BINARY says restic is. Install restic 0.19.1 there, or correct the path.'])
        ->and(BackupTools::problems($tool('restic', null, '/opt/restic', '/opt/restic'), $dump, $restore, '18.4'))
        ->toBe(['The program at /opt/restic didn’t answer as restic does. Check that TEME_RESTIC_BINARY names restic.'])
        ->and(BackupTools::problems($tool('restic', '0.16.4', 'restic'), $dump, $restore, '18.4'))
        ->toBe(['restic 0.16.4 is older than 0.19.1, the version Teme is tested with. Install restic 0.19.1.'])
        ->and(BackupTools::problems($restic, $tool('pg_dump', null, '/opt/pg/bin', null), $restore, '18.4'))
        ->toBe(['There’s no pg_dump in /opt/pg/bin, where TEME_POSTGRES_BINDIR says PostgreSQL’s tools are. Install PostgreSQL 18’s command-line tools there, or correct the folder.'])
        ->and(BackupTools::problems($restic, $dump, $tool('pg_restore', null), '18.4'))
        ->toBe(['The program at /opt/bin/tool didn’t answer as pg_restore does.'])
        ->and(BackupTools::problems($restic, $tool('pg_dump', '17.5'), $tool('pg_restore', '17.5'), '17.2'))->toBe([
            'pg_dump 17.5 is older than PostgreSQL 18, the oldest Teme backs up with. Install PostgreSQL 18’s command-line tools or later.',
            'pg_restore 17.5 is older than PostgreSQL 18, the oldest Teme backs up with. Install PostgreSQL 18’s command-line tools or later.',
        ])
        ->and(BackupTools::problems($restic, $dump, $restore, '19.1'))
        ->toBe(['pg_dump 18.6 can’t copy this site’s database, which runs PostgreSQL 19.1. Install PostgreSQL 19’s command-line tools or later.'])
        ->and(BackupTools::problems($restic, $dump, $tool('pg_restore', '18.4'), '18.4'))
        ->toBe(['pg_dump 18.6 and pg_restore 18.4 come from different PostgreSQL installations. Use the two from one folder.']);
});

it('reads restic\'s and PostgreSQL\'s own answers about their versions', function (): void {
    expect(BackupTools::resticVersionFrom('{"message_type":"version","version":"0.19.1","go_version":"go1.26.4","go_os":"linux","go_arch":"amd64"}'))->toBe('0.19.1')
        ->and(BackupTools::resticVersionFrom('restic 0.19.1 compiled with go1.26.4 on linux/amd64'))->toBeNull()
        ->and(BackupTools::resticVersionFrom('{"message_type":"summary"}'))->toBeNull()
        ->and(BackupTools::postgresVersionFrom('pg_dump (PostgreSQL) 18.6'))->toBe('18.6')
        ->and(BackupTools::postgresVersionFrom("pg_restore (PostgreSQL) 18.6 (Debian 18.6-1.pgdg24.04+1)\n"))->toBe('18.6')
        ->and(BackupTools::postgresVersionFrom('pg_dump (PostgreSQL) 19beta1'))->toBe('19')
        ->and(BackupTools::postgresVersionFrom('mysqldump  Ver 8.4.6'))->toBeNull()
        ->and(BackupTools::isPath('restic'))->toBeFalse()
        ->and(BackupTools::isPath('/usr/local/bin/restic'))->toBeTrue()
        ->and(BackupTools::isPath('C:\Program Files\PostgreSQL\18\bin'))->toBeTrue();
});
