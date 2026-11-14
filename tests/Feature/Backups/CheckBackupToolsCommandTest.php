<?php

declare(strict_types=1);

use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\PostgresProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('prints each program\'s version and path beside the server\'s, and passes', function (): void {
    $tools = app(BackupTools::class);
    $report = $tools->check();

    $code = Artisan::call('teme:backup:tools');
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain("restic: {$report->tools[0]->version} at {$tools->restic()}")
        ->and($output)->toContain("pg_dump: {$report->tools[1]->version} at {$tools->postgres(PostgresProgram::Dump)}")
        ->and($output)->toContain("pg_restore: {$report->tools[2]->version} at {$tools->postgres(PostgresProgram::Restore)}")
        ->and($output)->toContain("PostgreSQL server: {$report->serverVersion}")
        ->and($output)->toContain('restic and PostgreSQL’s tools are ready for backups.');
});

it('fails, naming what to install, when restic isn\'t where the configuration says', function (): void {
    $nowhere = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-no-such-folder'.DIRECTORY_SEPARATOR.'restic';
    config(['teme.backups.restic' => $nowhere]);

    $code = Artisan::call('teme:backup:tools');
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('restic: missing')
        ->and($output)->toContain("There’s no program at {$nowhere}, where TEME_RESTIC_BINARY says restic is. Install restic 0.19.1 there, or correct the path.")
        ->and($output)->not->toContain('ready for backups');
});

it('fails when PostgreSQL\'s tools aren\'t in the configured folder', function (): void {
    $nowhere = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-no-such-folder';
    config(['teme.backups.postgres_bindir' => $nowhere]);

    $code = Artisan::call('teme:backup:tools');
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('pg_dump: missing')
        ->and($output)->toContain('pg_restore: missing')
        ->and($output)->toContain("There’s no pg_restore in {$nowhere}, where TEME_POSTGRES_BINDIR says PostgreSQL’s tools are. Install PostgreSQL 18’s command-line tools there, or correct the folder.");
});
