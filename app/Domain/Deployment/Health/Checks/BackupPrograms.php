<?php

/**
 * Says whether restic and PostgreSQL's tools are here at versions that can
 * back this site up, the server's PostgreSQL no newer than pg_dump (I-366).
 * An error in production once backups are set up, so a deploy refuses a
 * server that couldn't take its backup; a warning otherwise.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Deployment\BackupsSetUp;
use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;

final readonly class BackupPrograms implements DoctorCheck
{
    public function __construct(private BackupTools $tools, private BackupsSetUp $backups) {}

    public function title(): string
    {
        return 'Backup programs';
    }

    public function examine(): array
    {
        $report = $this->tools->check();

        if ($report->ready()) {
            [$restic, $dump, $restore] = $report->tools;

            return [new Finding(Severity::Ok, "restic {$restic->version}, pg_dump {$dump->version} and pg_restore {$restore->version} can back up this site's PostgreSQL {$report->serverVersion}.")];
        }

        $severity = Severity::inProduction(app()->isProduction() && $this->backups->now());

        return array_map(
            fn (string $problem): Finding => new Finding($severity, $problem, 'php artisan teme:backup:tools shows where Teme looked for each program.'),
            $report->problems,
        );
    }
}
