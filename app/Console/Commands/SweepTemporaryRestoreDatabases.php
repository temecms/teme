<?php

/**
 * teme:backup:sweep — drops the temporary databases a test restore under
 * TEME_BACKUPS=host left behind when its run died (the copy holds the site's
 * data unencrypted). The test restore sweeps before each test too; this runs
 * daily as well, so a leftover never waits for the next test.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupAlreadyRunning;
use App\Domain\Backups\BackupLock;
use App\Domain\Backups\Verify\TemporaryDatabase;
use Illuminate\Console\Command;

final class SweepTemporaryRestoreDatabases extends Command
{
    /** @var string */
    protected $signature = 'teme:backup:sweep';

    /** @var string */
    protected $description = 'Drop the temporary databases a test restore left behind';

    public function handle(BackupLock $lock): int
    {
        if (! TemporaryDatabase::configured()) {
            $this->line('No restore account is named, so there is nothing to sweep.');

            return self::SUCCESS;
        }

        // Under the backup lock, so a test restore that is running, or about to connect, is never swept.
        if ($lock->isHeld()) {
            $this->line('A backup is running, so the sweep waits for tomorrow.');

            return self::SUCCESS;
        }

        try {
            $dropped = $lock->during(fn (): int => TemporaryDatabase::sweep());
        } catch (BackupAlreadyRunning) {
            $this->line('A backup is running, so the sweep waits for tomorrow.');

            return self::SUCCESS;
        }

        $this->line($dropped === 0 ? 'No temporary restore databases left behind.' : "Dropped {$dropped} temporary restore database(s).");

        return self::SUCCESS;
    }
}
