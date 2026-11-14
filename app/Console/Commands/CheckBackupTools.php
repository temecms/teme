<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\Tools\BackupTools;
use Illuminate\Console\Command;

/**
 * `teme:backup:tools`: where restic, pg_dump and pg_restore are and which
 * versions they are, beside the database server's, and what is wrong in
 * words. It fails when any is missing or too old to back this site up
 * (I-366). It reads and changes nothing, so it acts as no one.
 */
final class CheckBackupTools extends Command
{
    /** @var string */
    protected $signature = 'teme:backup:tools';

    /** @var string */
    protected $description = 'Check that restic and PostgreSQL’s tools are installed, at versions Teme can back up with';

    public function handle(BackupTools $tools): int
    {
        $report = $tools->check();

        // Plain lines, never two columns: a long path would wrap and break.
        foreach ($report->tools as $tool) {
            $this->line(match (true) {
                $tool->path === null => "{$tool->name}: missing",
                $tool->version === null => "{$tool->name}: at {$tool->path}",
                default => "{$tool->name}: {$tool->version} at {$tool->path}",
            });
        }

        $this->line("PostgreSQL server: {$report->serverVersion}");

        foreach ($report->problems as $problem) {
            $this->error($problem);
        }

        if (! $report->ready()) {
            return self::FAILURE;
        }

        $this->info('restic and PostgreSQL’s tools are ready for backups.');

        return self::SUCCESS;
    }
}
