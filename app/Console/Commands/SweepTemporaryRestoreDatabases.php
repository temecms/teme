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

use App\Domain\Backups\Verify\TemporaryDatabase;
use Illuminate\Console\Command;

final class SweepTemporaryRestoreDatabases extends Command
{
    /** @var string */
    protected $signature = 'teme:backup:sweep';

    /** @var string */
    protected $description = 'Drop the temporary databases a test restore left behind';

    public function handle(): int
    {
        if (! TemporaryDatabase::configured()) {
            $this->line('No restore account is named, so there is nothing to sweep.');

            return self::SUCCESS;
        }

        $dropped = TemporaryDatabase::sweep();
        $this->line($dropped === 0 ? 'No temporary restore databases left behind.' : "Dropped {$dropped} temporary restore database(s).");

        return self::SUCCESS;
    }
}
